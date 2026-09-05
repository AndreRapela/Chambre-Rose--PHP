<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
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
    public function consume(string $raw): ?int
    {
        $hash = hash('sha256', $raw);
        $this->pdo->beginTransaction();

        try {
            $s = $this->pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=:hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP FOR UPDATE');
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
}

final class MailService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string> $vars */
    public function send(string $recipient, string $template, string $locale, array $vars): void
    {
        $locale = in_array($locale, ['fr','en','pt'], true) ? $locale : 'fr';
        [$subject,$body] = $this->render($template, $locale, $vars);
        $transport = strtolower(Config::get('MAIL_TRANSPORT', 'log') ?? 'log');
        $status = 'LOGGED';

        try {
            if ($transport === 'mail') {
                $headers = 'Content-Type: text/plain; charset=UTF-8' . "\r\n" . 'From: ' . (Config::get('MAIL_FROM', 'noreply@chambre-rose.com') ?? 'noreply@chambre-rose.com');
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
    }
    /**
     * @param array<string, string> $v
     * @return array{string, string}
     */
    private function render(string $template, string $locale, array $v): array
    {
        $name = $v['name'] ?? '';
        $url = $v['url'] ?? '';
        $texts = [
          'password_reset' => [
            'fr' => ['Réinitialisation de votre mot de passe',"Bonjour {$name},\n\nNous avons reçu une demande de réinitialisation. Utilisez ce lien sécurisé, valable une heure :\n{$url}\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message.\n\nL’équipe Chambre Rose"],
            'en' => ['Reset your password',"Hello {$name},\n\nWe received a password reset request. Use this secure link within one hour:\n{$url}\n\nIf you did not request this, ignore this message.\n\nChambre Rose team"],
            'pt' => ['Redefinição de senha',"Olá {$name},\n\nRecebemos um pedido de redefinição de senha. Use este link seguro em até uma hora:\n{$url}\n\nSe não foi você, ignore esta mensagem.\n\nEquipe Chambre Rose"],
          ],
          'account_created' => [
            'fr' => ['Compte reçu',"Bonjour {$name},\n\nVotre compte professionnel a bien été reçu. Notre équipe l’examinera dans un délai de 24 heures. Vous recevrez un message après la décision.\n\nL’équipe Chambre Rose"],
            'en' => ['Account received',"Hello {$name},\n\nYour professional account has been received. Our team will review it within 24 hours and notify you after a decision.\n\nChambre Rose team"],
            'pt' => ['Conta recebida',"Olá {$name},\n\nSua conta profissional foi recebida. Nossa equipe fará a análise em até 24 horas e avisará após a decisão.\n\nEquipe Chambre Rose"],
          ],
          'account_approved' => [
            'fr' => ['Compte approuvé',"Bonjour {$name},\n\nVotre compte Chambre Rose a été approuvé. Vous pouvez maintenant vous connecter.\n\nL’équipe Chambre Rose"],
            'en' => ['Account approved',"Hello {$name},\n\nYour Chambre Rose account has been approved. You can now sign in.\n\nChambre Rose team"],
            'pt' => ['Conta aprovada',"Olá {$name},\n\nSua conta Chambre Rose foi aprovada. Você já pode entrar.\n\nEquipe Chambre Rose"],
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
