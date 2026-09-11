<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{challenge: string, code: string} */
    public function issueCode(int $userId): array
    {
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND used_at IS NULL'
        );
        $statement->execute(['id' => $userId]);

        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens '
            . '(user_id,token_hash,verification_code_hash,verification_attempts,expires_at,created_at) '
            . 'VALUES (:uid,:hash,:code_hash,0,:expires,CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'uid' => $userId,
            'hash' => hash('sha256', $challenge),
            'code_hash' => hash('sha256', $code),
            'expires' => $expires,
        ]);

        return ['challenge' => $challenge, 'code' => $code];
    }

    public function anonymousChallenge(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function issue(int $userId): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND used_at IS NULL'
        );
        $statement->execute(['id' => $userId]);

        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens '
            . '(user_id,token_hash,expires_at,created_at) '
            . 'VALUES (:uid,:hash,:expires,CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'uid' => $userId,
            'hash' => $hash,
            'expires' => $expires,
        ]);

        return $raw;
    }

    public function verifyCode(string $raw, string $code): bool
    {
        if ($raw === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $sql = 'SELECT id,verification_code_hash,verification_attempts,verified_at '
                . 'FROM password_reset_tokens '
                . 'WHERE token_hash=:hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP';
            if ($this->isLockable()) {
                $sql .= ' FOR UPDATE';
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['hash' => hash('sha256', $raw)]);
            $row = $statement->fetch();
            if (!is_array($row) || !is_string($row['verification_code_hash'] ?? null)) {
                $this->pdo->rollBack();

                return false;
            }
            if ($row['verified_at'] !== null) {
                $this->pdo->commit();

                // Keep retries idempotent for the same code, but never allow
                // an arbitrary code after the token has already been verified.
                return hash_equals($row['verification_code_hash'], hash('sha256', $code));
            }

            $attempts = (int) ($row['verification_attempts'] ?? 0);
            if ($attempts >= 5) {
                $this->pdo->rollBack();
                throw new ApiException(429, 'Too many verification attempts. Request a new code.');
            }

            $valid = hash_equals($row['verification_code_hash'], hash('sha256', $code));
            $statement = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET verification_attempts=:attempts'
                . ($valid ? ',verified_at=CURRENT_TIMESTAMP' : '')
                . ' WHERE id=:id'
            );
            $statement->execute(['id' => $row['id'], 'attempts' => $attempts + 1]);
            $this->pdo->commit();

            return $valid;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function consume(string $raw): ?int
    {
        $hash = hash('sha256', $raw);
        $this->pdo->beginTransaction();

        try {
            $sql = 'SELECT id,user_id FROM password_reset_tokens WHERE token_hash=:hash '
                . 'AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP '
                . 'AND (verification_code_hash IS NULL OR verified_at IS NOT NULL)';
            if ($this->isLockable()) {
                $sql .= ' FOR UPDATE';
            }
            $s = $this->pdo->prepare($sql);
            $s->execute(['hash' => $hash]);
            $r = $s->fetch();
            if (!is_array($r)) {
                $this->pdo->rollBack();

                return null;
            }
            $statement = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=:id'
            );
            $statement->execute(['id' => $r['id']]);
            $this->pdo->commit();

            return (int) $r['user_id'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function isLockable(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite';
    }
}

final class MailService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,string> $vars
     * @return 'SENT'|'LOGGED'|'FAILED'
     */
    public function send(string $recipient, string $template, string $locale, array $vars): string
    {
        // Portuguese is not a supported communication language for the site.
        // Keep French when explicitly selected and use English for every other
        // value so an old/stale `pt` preference can never leak into an email.
        $locale = strtolower($locale) === 'fr' ? 'fr' : 'en';
        [$subject, $textBody, $htmlBody] = $this->render($template, $locale, $vars);
        $transport = strtolower(Config::get('MAIL_TRANSPORT') ?? '');
        $environment = strtolower(Config::get('APP_ENV', 'development') ?? 'development');
        // Logging is useful locally, but silently leaving production emails in
        // the database is surprising and prevents approval notifications from
        // reaching applicants. An explicit MAIL_ALLOW_LOG=true opt-out keeps
        // diagnostics available for controlled staging environments.
        if ($transport === '' || ($transport === 'log'
            && $environment === 'production'
            && !Config::bool('MAIL_ALLOW_LOG', false))) {
            $transport = $environment === 'production' ? 'mail' : 'log';
        }
        $status = 'LOGGED';

        try {
            if ($transport === 'mail') {
                $from = Config::get('MAIL_FROM', 'noreply@chambre-rose.com') ?? 'noreply@chambre-rose.com';
                $boundary = '=_ChambreRose_' . bin2hex(random_bytes(12));
                $headers = implode("\r\n", [
                    'From: ' . $from,
                    'Reply-To: ' . $from,
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                    'X-Mailer: Chambre Rose',
                ]);
                $status = mail($recipient, $subject, $this->multipart($boundary, $textBody, $htmlBody), $headers) ? 'SENT' : 'FAILED';
            } elseif ($transport === 'smtp') {
                $this->smtp($recipient, $subject, $textBody, $htmlBody);
                $status = 'SENT';
            } elseif ($transport !== 'log') {
                throw new \RuntimeException('Unsupported mail transport.');
            }
        } catch (\Throwable $e) {
            $status = 'FAILED';
            error_log('[Chambre Rose API] Email delivery failed for template ' . $template . '.');
        }
        // Keep the plain-text alternative for diagnostics and tests. Never
        // persist a reset URL/challenge in the outbox table.
        $storedBody = $template === 'password_reset'
            ? preg_replace('/https?:\/\/\S+/u', '[secure reset link omitted]', $textBody) ?? '[reset email redacted]'
            : $textBody;
        $sentAt = $status === 'SENT' ? 'CURRENT_TIMESTAMP' : 'NULL';
        $statement = $this->pdo->prepare(
            'INSERT INTO email_outbox '
            . '(recipient,template,locale,subject,body,delivery_status,created_at,sent_at) '
            . "VALUES (:recipient,:template,:locale,:subject,:body,:status,CURRENT_TIMESTAMP,{$sentAt})"
        );
        $statement->execute([
            'recipient' => $recipient,
            'template' => $template,
            'locale' => $locale,
            'subject' => $subject,
            'body' => $storedBody,
            'status' => $status,
        ]);
        if ($transport === 'log') {
            error_log('[Chambre Rose API] Email queued in development: ' . $template . ' to ' . self::mask($recipient) . '.');
        }

        return $status;
    }
    /**
     * @param array<string, string> $v
     * @return array{string, string, string}
     */
    private function render(string $template, string $locale, array $v): array
    {
        $name = trim($v['name'] ?? '') ?: ($locale === 'fr' ? 'vous' : 'there');
        $url = trim($v['url'] ?? '');
        $code = trim($v['code'] ?? '');
        $copy = [
            'en' => [
                'password_reset' => [
                    'subject' => 'Your Chambre Rose verification code',
                    'eyebrow' => 'SECURITY CHECK',
                    'title' => 'Reset your password',
                    'intro' => 'We received a request to reset your Chambre Rose password.',
                    'code_label' => 'Your six-digit verification code',
                    'instructions' => 'Enter this code on the reset page within one hour. The code can only be used once.',
                    'security' => 'If you did not request this, you can safely ignore this email.',
                    'cta' => 'Open password reset',
                    'footer' => 'Chambre Rose security team',
                ],
                'account_created' => [
                    'subject' => 'Your Chambre Rose registration is under review',
                    'eyebrow' => 'WELCOME TO CHAMBRE ROSE',
                    'title' => 'Your profile is in review',
                    'intro' => 'Your professional account has been created and is waiting for administrator approval.',
                    'instructions' => 'Our team will review your profile within 24 hours. You will receive another email as soon as a decision is made.',
                    'security' => 'You do not need to do anything else right now.',
                    'cta' => 'Visit Chambre Rose',
                    'footer' => 'Chambre Rose team',
                ],
                'account_approved' => [
                    'subject' => 'Your Chambre Rose account is approved',
                    'eyebrow' => 'ACCESS GRANTED',
                    'title' => 'You are ready to sign in',
                    'intro' => 'Your Chambre Rose account has been approved by an administrator.',
                    'instructions' => 'Sign in to complete your profile, discover approved profiles and use private messaging.',
                    'security' => 'Keep your login details private and never share your password.',
                    'cta' => 'Sign in to Chambre Rose',
                    'footer' => 'Chambre Rose team',
                ],
                'account_rejected' => [
                    'subject' => 'Update on your Chambre Rose application',
                    'eyebrow' => 'APPLICATION UPDATE',
                    'title' => 'Your application needs attention',
                    'intro' => 'Your professional profile was not approved at this time.',
                    'instructions' => 'Please contact support if you need more information or would like help with your next steps.',
                    'security' => 'Thank you for your interest in Chambre Rose.',
                    'cta' => 'Contact support',
                    'footer' => 'Chambre Rose team',
                ],
            ],
            'fr' => [
                'password_reset' => [
                    'subject' => 'Votre code de vérification Chambre Rose',
                    'eyebrow' => 'CONTRÔLE DE SÉCURITÉ',
                    'title' => 'Réinitialiser votre mot de passe',
                    'intro' => 'Nous avons reçu une demande de réinitialisation de votre mot de passe Chambre Rose.',
                    'code_label' => 'Votre code de vérification à six chiffres',
                    'instructions' => 'Saisissez ce code sur la page de réinitialisation dans l’heure. Il ne peut être utilisé qu’une seule fois.',
                    'security' => 'Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.',
                    'cta' => 'Ouvrir la réinitialisation',
                    'footer' => 'Équipe sécurité Chambre Rose',
                ],
                'account_created' => [
                    'subject' => 'Votre inscription Chambre Rose est en cours d’examen',
                    'eyebrow' => 'BIENVENUE SUR CHAMBRE ROSE',
                    'title' => 'Votre profil est en cours d’examen',
                    'intro' => 'Votre compte professionnel a été créé et attend la validation d’un administrateur.',
                    'instructions' => 'Notre équipe examinera votre profil sous 24 heures. Vous recevrez un nouvel e-mail dès qu’une décision sera prise.',
                    'security' => 'Vous n’avez rien d’autre à faire pour le moment.',
                    'cta' => 'Visiter Chambre Rose',
                    'footer' => 'Équipe Chambre Rose',
                ],
                'account_approved' => [
                    'subject' => 'Votre compte Chambre Rose est approuvé',
                    'eyebrow' => 'ACCÈS AUTORISÉ',
                    'title' => 'Vous pouvez vous connecter',
                    'intro' => 'Votre compte Chambre Rose a été approuvé par un administrateur.',
                    'instructions' => 'Connectez-vous pour terminer votre profil, découvrir les profils approuvés et utiliser la messagerie privée.',
                    'security' => 'Gardez vos identifiants privés et ne partagez jamais votre mot de passe.',
                    'cta' => 'Se connecter à Chambre Rose',
                    'footer' => 'Équipe Chambre Rose',
                ],
                'account_rejected' => [
                    'subject' => 'Mise à jour de votre demande Chambre Rose',
                    'eyebrow' => 'MISE À JOUR DE LA DEMANDE',
                    'title' => 'Votre demande nécessite votre attention',
                    'intro' => 'Votre profil professionnel n’a pas été approuvé pour le moment.',
                    'instructions' => 'Contactez le support si vous souhaitez davantage d’informations ou de l’aide pour la suite.',
                    'security' => 'Merci de votre intérêt pour Chambre Rose.',
                    'cta' => 'Contacter le support',
                    'footer' => 'Équipe Chambre Rose',
                ],
            ],
        ];
        $entry = $copy[$locale][$template] ?? throw new \RuntimeException('Unknown email template.');
        $isReset = $template === 'password_reset';
        $displayName = $locale === 'fr' ? 'Bonjour ' . $name : 'Hello ' . $name;
        $text = $displayName . ",\n\n" . $entry['intro'] . "\n\n";
        if ($isReset) {
            $text .= $entry['code_label'] . ":\n\n" . $code . "\n\n";
        }
        $text .= $entry['instructions'];
        if ($url !== '') {
            $text .= "\n\n" . ($locale === 'fr' ? 'Ouvrir le lien :' : 'Open the link:') . "\n" . $url;
        }
        $text .= "\n\n" . $entry['security'] . "\n\n" . $entry['footer'];

        $content = '<p style="margin:0 0 20px;color:#4d3a40;font-size:16px;line-height:1.65;">'
            . self::escape($entry['intro']) . '</p>';
        if ($isReset) {
            $content .= '<div style="margin:26px 0 24px;padding:22px 18px;border:1px solid #f1c5d1;border-radius:18px;background:#fff5f7;text-align:center;">'
                . '<div style="color:#922843;font-size:12px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;">'
                . self::escape($entry['code_label']) . '</div>'
                . '<div style="margin-top:12px;color:#9e173b;font-size:36px;font-weight:800;letter-spacing:9px;line-height:1.1;">'
                . self::escape($code) . '</div></div>';
        }
        $content .= '<p style="margin:0 0 16px;color:#4d3a40;font-size:16px;line-height:1.65;">'
            . self::escape($entry['instructions']) . '</p>'
            . '<div style="margin:22px 0;padding:14px 16px;border-left:4px solid #c53a5d;border-radius:8px;background:#fff8fa;color:#6b4b55;font-size:14px;line-height:1.55;">'
            . '<span style="color:#9e173b;font-weight:800;">✦ </span>' . self::escape($entry['security']) . '</div>';

        $siteUrl = rtrim(Config::get('APP_FRONTEND_URL', 'https://www.chambre-rose.com') ?? 'https://www.chambre-rose.com', '/');
        $ctaUrl = $url !== '' ? $url : $siteUrl;
        $html = $this->htmlTemplate(
            $entry['eyebrow'],
            $entry['title'],
            $content,
            $entry['cta'],
            $ctaUrl,
            $entry['footer'],
            $locale
        );

        return [$entry['subject'], $text, $html];
    }
    private function htmlTemplate(
        string $eyebrow,
        string $title,
        string $content,
        string $ctaLabel,
        string $ctaUrl,
        string $footer,
        string $locale
    ): string {
        $siteUrl = rtrim(Config::get('APP_FRONTEND_URL', 'https://www.chambre-rose.com') ?? 'https://www.chambre-rose.com', '/');
        $assetBase = rtrim(Config::get('MAIL_ASSET_BASE_URL', 'https://www.chambre-rose.com') ?? 'https://www.chambre-rose.com', '/');
        $safeSiteUrl = self::escape($siteUrl);
        $safeCtaUrl = self::escape($ctaUrl);
        $safeLogoUrl = self::escape($assetBase . '/assets/brand-logo-84.webp');
        $safeEyebrow = self::escape($eyebrow);
        $safeTitle = self::escape($title);
        $safeCtaLabel = self::escape($ctaLabel);
        $safeFooter = self::escape($footer);
        $lang = $locale === 'fr' ? 'fr' : 'en';
        $tagline = $locale === 'fr' ? 'Privé · Élégant · Personnel' : 'Private · Polished · Personal';
        $automated = $locale === 'fr'
            ? 'Ceci est un message automatique de Chambre Rose. Merci de ne pas répondre.'
            : 'This is an automated message from Chambre Rose. Please do not reply.';

        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>'
            . $safeTitle . '</title></head><body style="margin:0;padding:0;background:#f8f0f2;color:#2d1b21;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $safeEyebrow . ' · ' . $safeTitle . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f8f0f2;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;overflow:hidden;border-radius:24px;background:#ffffff;box-shadow:0 18px 45px rgba(83,24,43,.14);">'
            . '<tr><td style="padding:28px 32px;background:linear-gradient(135deg,#2a171e 0%,#4b1b2c 100%);">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td width="48" valign="middle"><a href="' . $safeSiteUrl . '" style="text-decoration:none;"><img src="' . $safeLogoUrl . '" width="42" height="42" alt="Chambre Rose" style="display:block;width:42px;height:42px;border:0;outline:none;"></a></td>'
            . '<td valign="middle" style="padding-left:12px;"><a href="' . $safeSiteUrl . '" style="color:#ffffff;text-decoration:none;font-size:21px;font-weight:800;letter-spacing:.2px;">Chambre Rose</a><div style="margin-top:3px;color:#f5cbd6;font-size:12px;letter-spacing:1.4px;text-transform:uppercase;">' . self::escape($tagline) . '</div></td>'
            . '<td width="42" align="right" valign="middle" style="color:#f5cbd6;font-size:26px;">✦</td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="padding:34px 34px 30px;background:#ffffff;"><div style="color:#c53a5d;font-size:12px;font-weight:800;letter-spacing:1.8px;">'
            . $safeEyebrow . '</div><h1 style="margin:10px 0 16px;color:#2d1b21;font-size:30px;line-height:1.2;letter-spacing:-.4px;">'
            . $safeTitle . '</h1>' . $content
            . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:26px 0 8px;"><tr><td style="border-radius:999px;background:#c53a5d;"><a href="' . $safeCtaUrl . '" style="display:inline-block;padding:13px 21px;border:1px solid #c53a5d;border-radius:999px;color:#ffffff;font-size:15px;font-weight:800;text-decoration:none;">'
            . $safeCtaLabel . ' <span aria-hidden="true">→</span></a></td></tr></table>'
            . '</td></tr><tr><td style="padding:18px 34px 24px;border-top:1px solid #f2dde3;background:#fffafb;color:#7b626a;font-size:12px;line-height:1.6;">'
            . '<span style="color:#9e173b;font-size:16px;">♡</span> ' . $safeFooter . '<br><span style="color:#9a858b;">' . self::escape($automated) . '</span>'
            . '</td></tr></table></td></tr></table></body></html>';
    }
    private function multipart(string $boundary, string $textBody, string $htmlBody): string
    {
        $encodedText = rtrim(chunk_split(base64_encode($textBody), 76, "\r\n"));
        $encodedHtml = rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n"));

        return '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $encodedText . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $encodedHtml . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";
    }
    private function smtp(string $to, string $subject, string $textBody, string $htmlBody): void
    {
        $host = Config::get('SMTP_HOST');
        $port = Config::int('SMTP_PORT', 587);
        if (!$host) {
            throw new \RuntimeException('SMTP_HOST missing.');
        }
        $secure = strtolower(Config::get('SMTP_SECURE', 'tls') ?? 'tls');
        $target = ($secure === 'ssl' ? 'ssl://' : '') . $host;
        $socket = fsockopen($target, $port, $errno, $error, 10);
        if (!$socket) {
            throw new \RuntimeException('SMTP connection failed.');
        }stream_set_timeout($socket, 10);
        $expect = function (array $codes) use ($socket): void {
            $line = '';
            do {
                $chunk = fgets($socket, 515);
                if ($chunk === false) {
                    throw new \RuntimeException('SMTP read failed.');
                }
                $line .= $chunk;
            } while (isset($chunk[3]) && $chunk[3] === '-');
            if (!in_array((int)substr($line, 0, 3), $codes, true)) {
                throw new \RuntimeException('SMTP rejected command.');
            }
        };
        $send = function (string $command, array $codes) use ($socket, $expect): void {
            fwrite($socket, $command . "\r\n");
            $expect($codes);
        };
        $expect([220]);
        $send('EHLO chambre-rose.com', [250]);
        if ($secure === 'tls') {
            $send('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP TLS failed.');
            }
            $send('EHLO chambre-rose.com', [250]);
        }
        $user = Config::get('SMTP_USERNAME');
        $pass = Config::get('SMTP_PASSWORD');
        if ($user !== null && $pass !== null) {
            $send('AUTH LOGIN', [334]);
            $send(base64_encode($user), [334]);
            $send(base64_encode($pass), [235]);
        }
        $from = Config::get('MAIL_FROM', 'noreply@chambre-rose.com') ?? 'noreply@chambre-rose.com';
        $send('MAIL FROM:<' . $from . '>', [250]);
        $send('RCPT TO:<' . $to . '>', [250,251]);
        $send('DATA', [354]);
        $boundary = '=_ChambreRose_' . bin2hex(random_bytes(12));
        $headers = "From: {$from}\r\nTo: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $payload = $headers . $this->multipart($boundary, $textBody, $htmlBody);
        $payload = str_replace("\n.", "\n..", $payload) . "\r\n.";
        $send($payload, [250]);
        $send('QUIT', [221]);
        fclose($socket);
    }
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 1) . '***@' . $domain;
    }
}
