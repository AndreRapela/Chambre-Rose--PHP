<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class Mailer
{
    public function sendRegistrationConfirmation(string $email, string $firstName): void
    {
        $name = self::escape($firstName);
        $body = $this->layout(
            'Registration received',
            "<p>Hello {$name},</p><p>We have received your Chambre Rose registration.</p>"
            . '<p>Our team will now review your information, establishment photo and store. Your account is not active yet.</p>'
            . '<p>If approved, your store will be published on Chambre Rose within 24 hours. We will send the decision by email, and sign-in will become available after approval.</p>'
            . $this->button($this->frontendUrl(), 'Visit Chambre Rose')
            . '<p>If you did not create this account, please contact Chambre Rose.</p>'
        );
        $this->send($email, 'We received your Chambre Rose registration', $body,
            "Hello {$firstName},\n\nWe received your Chambre Rose registration. Our team will review your "
            . "information, establishment photo and store.\n\nIf approved, your store will be published on Chambre Rose "
            . "within 24 hours. We will send the decision by email, and sign-in will become available after approval.\n\n"
            . $this->frontendUrl());
    }

    public function sendAccountReviewDecision(string $email, string $firstName, string $status): void
    {
        $name = self::escape($firstName);
        if ($status === 'APPROVED') {
            $body = $this->layout(
                'Your account has been approved',
                "<p>Hello {$name},</p><p>Your Chambre Rose registration has been approved.</p>"
                . '<p>You can now sign in and access your private area.</p>'
                . $this->button($this->frontendUrl() . '/auth/login', 'Sign in')
            );
            $this->send($email, 'Your Chambre Rose account has been approved', $body,
                "Hello {$firstName},\n\nYour Chambre Rose registration has been approved.\n\nSign in: "
                . $this->frontendUrl() . '/auth/login');
            return;
        }

        $body = $this->layout(
            'Registration review completed',
            "<p>Hello {$name},</p><p>We have completed the review of your Chambre Rose registration.</p>"
            . '<p>We are unable to approve the account at this time. If you believe this is a mistake or would like '
            . 'more information, please contact the Chambre Rose team.</p>'
            . $this->button($this->frontendUrl() . '/contact', 'Contact Chambre Rose')
        );
        $this->send($email, 'Update on your Chambre Rose registration', $body,
            "Hello {$firstName},\n\nWe completed the review of your Chambre Rose registration and are unable "
            . "to approve the account at this time.\n\nContact us: " . $this->frontendUrl() . '/contact');
    }

    public function sendPasswordReset(string $email, string $firstName, string $token): void
    {
        $url = $this->frontendUrl() . '/auth/reset-password?token=' . rawurlencode($token);
        $minutes = max(5, Config::int('PASSWORD_RESET_TTL_MINUTES', 30));
        $name = self::escape($firstName);
        $body = $this->layout(
            'Reset your password',
            "<p>Hello {$name},</p><p>We received a request to reset your Chambre Rose password.</p>"
            . $this->button($url, 'Reset password')
            . "<p>This link expires in {$minutes} minutes and can only be used once.</p>"
            . '<p>If you did not request a password reset, you can safely ignore this email.</p>'
        );
        $this->send($email, 'Reset your Chambre Rose password', $body,
            "Hello {$firstName},\n\nReset your password: {$url}\n\nThis link expires in {$minutes} minutes."
        );
    }

    public function sendNewsletterConfirmation(string $email): void
    {
        $body = $this->layout(
            'You are on the list',
            '<p>Thank you for subscribing to the Chambre Rose newsletter.</p>'
            . '<p>You will receive selected news, offers and inspiration from Chambre Rose.</p>'
            . $this->button($this->frontendUrl(), 'Visit Chambre Rose')
        );
        $this->send($email, 'Chambre Rose newsletter subscription', $body,
            "Thank you for subscribing to the Chambre Rose newsletter.\n\n" . $this->frontendUrl());
    }

    private function send(string $recipient, string $subject, string $html, string $text): void
    {
        $transport = strtolower(Config::get('MAIL_TRANSPORT', 'mail') ?? 'mail');
        if ($transport === 'mail') {
            $fromAddress = Config::get('MAIL_FROM_ADDRESS', 'no-reply@chambre-rose.com') ?? 'no-reply@chambre-rose.com';
            $fromName = Config::get('MAIL_FROM_NAME', 'Chambre Rose') ?? 'Chambre Rose';
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . self::header($fromName) . ' <' . self::header($fromAddress) . '>',
            ];
            if (!mail($recipient, $subject, $html, implode("\r\n", $headers))) {
                throw new \RuntimeException('The email transport rejected the message.');
            }
            return;
        }
        if ($transport !== 'smtp') {
            throw new \RuntimeException('MAIL_TRANSPORT must be smtp or mail.');
        }
        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException('PHPMailer is not installed. Run composer install.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = Config::get('SMTP_HOST', '') ?? '';
        $mail->Port = Config::int('SMTP_PORT', 587);
        $mail->SMTPAuth = Config::bool('SMTP_AUTH', true);
        $mail->Username = Config::get('SMTP_USERNAME', '') ?? '';
        $mail->Password = Config::get('SMTP_PASSWORD', '') ?? '';
        $encryption = strtolower(Config::get('SMTP_ENCRYPTION', 'tls') ?? 'tls');
        if (in_array($encryption, ['tls', 'ssl', 'smtps'], true)) {
            $mail->SMTPSecure = $encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(Config::get('MAIL_FROM_ADDRESS', 'no-reply@chambre-rose.com') ?? 'no-reply@chambre-rose.com',
            Config::get('MAIL_FROM_NAME', 'Chambre Rose') ?? 'Chambre Rose');
        $mail->addAddress($recipient);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    }

    private function layout(string $title, string $content): string
    {
        $safeTitle = self::escape($title);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" '
            . 'content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f8f1f4;'
            . 'font-family:Arial,sans-serif;color:#35272e"><table role="presentation" width="100%" cellspacing="0" '
            . 'cellpadding="0"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="100%" '
            . 'cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="background:#7d294b;color:#fff;padding:26px 32px;font-family:Georgia,serif;font-size:26px">'
            . 'Chambre Rose</td></tr><tr><td style="padding:32px;line-height:1.65"><h1 style="font-family:Georgia,serif;'
            . 'font-size:25px;margin:0 0 20px;color:#7d294b">' . $safeTitle . '</h1>' . $content
            . '<p style="margin-top:28px">Chambre Rose</p></td></tr></table></td></tr></table></body></html>';
    }

    private function button(string $url, string $label): string
    {
        return '<p style="margin:28px 0"><a href="' . self::escape($url) . '" style="display:inline-block;'
            . 'background:#7d294b;color:#fff;text-decoration:none;padding:13px 22px;border-radius:999px;font-weight:bold">'
            . self::escape($label) . '</a></p>';
    }

    private function frontendUrl(): string
    {
        return rtrim(Config::get('APP_FRONTEND_URL', 'https://www.chambre-rose.com') ?? 'https://www.chambre-rose.com', '/');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function header(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(int $userId, int $ttlMinutes): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(5, $ttlMinutes) . ' minutes')->format('Y-m-d H:i:s.u');
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=:user_id AND used_at IS NULL');
            $delete->execute(['user_id' => $userId]);
            $insert = $this->pdo->prepare('INSERT INTO password_reset_tokens '
                . '(user_id,token_hash,expires_at,created_at) VALUES (:user_id,:token_hash,:expires_at,CURRENT_TIMESTAMP)');
            $insert->execute(['user_id' => $userId, 'token_hash' => $hash, 'expires_at' => $expiresAt]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $token;
    }

    /** @param callable(int): void $updatePassword */
    public function consume(string $token, callable $updatePassword): void
    {
        $hash = hash('sha256', $token);
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT id,user_id FROM password_reset_tokens '
                . 'WHERE token_hash=:token_hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP FOR UPDATE');
            $select->execute(['token_hash' => $hash]);
            $row = $select->fetch();
            if (!is_array($row)) {
                throw new ApiException(400, 'This password reset link is invalid or has expired.');
            }
            $updatePassword((int) $row['user_id']);
            $mark = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=:id');
            $mark->execute(['id' => (int) $row['id']]);
            $invalidate = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=:user_id AND used_at IS NULL');
            $invalidate->execute(['user_id' => (int) $row['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

final class NewsletterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function subscribe(string $email): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO newsletter_subscribers (email,active,subscribed_at,updated_at)
                VALUES (:email,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE active=TRUE,updated_at=CURRENT_TIMESTAMP
                SQL);
        } else {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO newsletter_subscribers (email,active,subscribed_at,updated_at)
                VALUES (:email,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON CONFLICT (email) DO UPDATE SET active=TRUE,updated_at=CURRENT_TIMESTAMP
                SQL);
        }
        $statement->execute(['email' => strtolower(trim($email))]);
    }
}

final class AccountRecoveryService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $tokens,
        private readonly Mailer $mailer
    ) {
    }

    /** @param array<string, mixed> $input @return array{message: string} */
    public function forgotPassword(array $input): array
    {
        $email = Validator::emailOnly($input);
        $user = $this->users->findByEmail($email);
        if ($user !== null) {
            $token = $this->tokens->issue((int) $user['id'], Config::int('PASSWORD_RESET_TTL_MINUTES', 30));
            try {
                $this->mailer->sendPasswordReset($user['email'], $user['firstName'], $token);
            } catch (Throwable $exception) {
                error_log('[Chambre Rose API] Password reset email failed: ' . $exception->getMessage());
            }
        }
        return ['message' => 'If an account exists for this email, a password reset link has been sent.'];
    }

    /** @param array<string, mixed> $input @return array{message: string} */
    public function resetPassword(array $input): array
    {
        $data = Validator::passwordReset($input);
        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $this->tokens->consume($data['token'], fn (int $userId) => $this->users->updatePassword($userId, $hash));
        return ['message' => 'Your password has been updated. You can now sign in.'];
    }
}

final class NewsletterService
{
    public function __construct(private readonly NewsletterRepository $subscribers, private readonly Mailer $mailer)
    {
    }

    /** @param array<string, mixed> $input @return array{message: string} */
    public function subscribe(array $input): array
    {
        $email = Validator::emailOnly($input);
        $this->subscribers->subscribe($email);
        try {
            $this->mailer->sendNewsletterConfirmation($email);
        } catch (Throwable $exception) {
            error_log('[Chambre Rose API] Newsletter confirmation email failed: ' . $exception->getMessage());
        }
        return ['message' => 'You are subscribed to the Chambre Rose newsletter.'];
    }
}
