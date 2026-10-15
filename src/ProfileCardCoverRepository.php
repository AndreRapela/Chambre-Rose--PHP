<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class ProfileCardCoverRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<int> $userIds
     *  @return array<int, array{cardCoverUrl: string, cardCoverSrcSet: string}>
     */
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT user_id,width,source_hash FROM profile_card_covers WHERE user_id IN ('
            . $placeholders . ') ORDER BY user_id,width'
        );
        $statement->execute($userIds);
        $covers = [];
        $sources = [];
        foreach ($statement->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $width = (int) $row['width'];
            $url = '/api/profiles/' . $userId . '/card-cover/' . $width . '.webp?v=' . $row['source_hash'];
            $sources[$userId][] = $url . ' ' . $width . 'w';
            $covers[$userId] = ['cardCoverUrl' => $url, 'cardCoverSrcSet' => ''];
        }
        foreach ($covers as $userId => &$cover) {
            $cover['cardCoverSrcSet'] = implode(', ', $sources[$userId]);
        }
        unset($cover);

        return $covers;
    }

    /** @return array{cardCoverUrl: ?string, cardCoverSrcSet: ?string} */
    public function forUser(int $userId): array
    {
        return $this->forUsers([$userId])[$userId] ?? ['cardCoverUrl' => null, 'cardCoverSrcSet' => null];
    }

    /** @param list<array{width: int, height: int, contentType: string, size: int, bytes: string}> $variants */
    public function replace(int $userId, string $sourceHash, array $variants): void
    {
        $this->mutate($userId, function () use ($userId, $sourceHash, $variants): void {
            $statement = $this->pdo->prepare(
                'INSERT INTO profile_card_covers (user_id,width,height,image_data,source_hash)'
                . ' VALUES (:user,:width,:height,:bytes,:hash)'
            );
            foreach ($variants as $variant) {
                $statement->bindValue(':user', $userId, PDO::PARAM_INT);
                $statement->bindValue(':width', $variant['width'], PDO::PARAM_INT);
                $statement->bindValue(':height', $variant['height'], PDO::PARAM_INT);
                $statement->bindValue(':bytes', $variant['bytes'], PDO::PARAM_LOB);
                $statement->bindValue(':hash', $sourceHash);
                $statement->execute();
            }
        });
    }

    public function delete(int $userId): void
    {
        $this->mutate($userId, static function (): void {});
    }

    /** @return array{sourceHash: string}|null */
    public function metadata(int $userId, int $width): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT source_hash FROM profile_card_covers WHERE user_id=:user AND width=:width'
        );
        $statement->execute(['user' => $userId, 'width' => $width]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? ['sourceHash' => $hash] : null;
    }

    public function bytes(int $userId, int $width, string $sourceHash): string
    {
        $statement = $this->pdo->prepare(
            'SELECT image_data FROM profile_card_covers WHERE user_id=:user AND width=:width AND source_hash=:hash'
        );
        $statement->execute(['user' => $userId, 'width' => $width, 'hash' => $sourceHash]);
        $row = $statement->fetch();
        $bytes = is_array($row) ? $row['image_data'] : null;
        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }
        if (!is_string($bytes) || $bytes === '') {
            throw new ApiException(404, 'Card cover not found.');
        }

        return $bytes;
    }

    private function mutate(int $userId, callable $mutation): void
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:user FOR UPDATE');
            $lock->execute(['user' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(404, 'User not found.');
            }
            $delete = $this->pdo->prepare('DELETE FROM profile_card_covers WHERE user_id=:user');
            $delete->execute(['user' => $userId]);
            $mutation();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
