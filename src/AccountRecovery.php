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

                return true;
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
        $locale = in_array($locale, ['fr','en','pt'], true) ? $locale : 'fr';
        [$subject,$body] = $this->render($template, $locale, $vars);
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
                $headers = implode("\r\n", [
                    'From: ' . $from,
                    'Reply-To: ' . $from,
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    'X-Mailer: Chambre Rose',
                ]);
                $status = mail($recipient, $subject, $body, $headers) ? 'SENT' : 'FAILED';
            } elseif ($transport === 'smtp') {
                $this->smtp($recipient, $subject, $body);
                $status = 'SENT';
            } elseif ($transport !== 'log') {
                throw new \RuntimeException('Unsupported mail transport.');
            }
        } catch (\Throwable $e) {
            $status = 'FAILED';
            error_log('[Chambre Rose API] Email delivery failed for template ' . $template . '.');
        }
        $storedBody = $template === 'password_reset'
            ? preg_replace('/https?:\/\/\S+/u', '[secure reset link omitted]', $body) ?? '[reset email redacted]'
            : $body;
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
     * @return array{string, string}
     */
    private function render(string $template, string $locale, array $v): array
    {
        $name = $v['name'] ?? '';
        $url = $v['url'] ?? '';
        $code = $v['code'] ?? '';
        $texts = [
          'password_reset' => [
            'fr' => ['Code de réinitialisation du mot de passe',"Bonjour {$name},\n\nNous avons reçu une demande de réinitialisation. Saisissez ce code à 6 chiffres dans Chambre Rose dans l’heure :\n{$code}\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message.\n\nL’équipe Chambre Rose"],
            'en' => ['Password reset verification code',"Hello {$name},\n\nWe received a password reset request. Enter this six-digit code in Chambre Rose within one hour:\n{$code}\n\nIf you did not request this, ignore this message.\n\nChambre Rose team"],
            'pt' => ['Código de redefinição de senha',"Olá {$name},\n\nRecebemos um pedido de redefinição de senha. Digite este código de 6 números no Chambre Rose em até uma hora:\n{$code}\n\nSe não foi você, ignore este email.\n\nEquipe Chambre Rose"],
          ],
          'account_created' => [
            'fr' => ['Inscription reçue',"Bonjour {$name},\n\nVotre compte professionnel a été créé, mais il reste inactif jusqu’à sa validation par un administrateur. Notre équipe examinera votre profil sous 24 heures et vous enverra un e-mail dès que la décision sera prise.\n\nL’équipe Chambre Rose"],
            'en' => ['Registration received',"Hello {$name},\n\nYour professional account was created, but it remains inactive until an administrator approves your profile. Our team will review it within 24 hours and email you as soon as a decision is made.\n\nChambre Rose team"],
            'pt' => ['Cadastro recebido',"Olá {$name},\n\nSua conta profissional foi criada, mas permanece inativa até um administrador aprovar seu perfil. Nossa equipe fará a análise em até 24 horas e enviará um email assim que houver uma decisão.\n\nEquipe Chambre Rose"],
          ],
          'account_approved' => [
            'fr' => ['Compte approuvé',"Bonjour {$name},\n\nVotre compte Chambre Rose a été approuvé. Vous pouvez maintenant vous connecter ici :\n{$url}\n\nL’équipe Chambre Rose"],
            'en' => ['Account approved',"Hello {$name},\n\nYour Chambre Rose account has been approved. You can now sign in here:\n{$url}\n\nChambre Rose team"],
            'pt' => ['Conta aprovada',"Olá {$name},\n\nSua conta Chambre Rose foi aprovada. Você já pode entrar por este link:\n{$url}\n\nEquipe Chambre Rose"],
          ],
          'account_rejected' => [
            'fr' => ['Mise à jour de votre demande',"Bonjour {$name},\n\nVotre demande n’a pas été approuvée. Contactez le support si vous souhaitez davantage d’informations.\n\nL’équipe Chambre Rose"],
            'en' => ['Application update',"Hello {$name},\n\nYour application was not approved. Contact support if you need more information.\n\nChambre Rose team"],
            'pt' => ['Atualização do cadastro',"Olá {$name},\n\nSeu cadastro não foi aprovado. Entre em contato com o suporte caso precise de mais informações.\n\nEquipe Chambre Rose"],
          ],
        ];

        return $texts[$template][$locale] ?? throw new \RuntimeException('Unknown email template.');
    }
    private function smtp(string $to, string $subject, string $body): void
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
        $payload = "From: {$from}\r\nTo: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.";
        $send($payload, [250]);
        $send('QUIT', [221]);
        fclose($socket);
    }
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 1) . '***@' . $domain;
    }
}
