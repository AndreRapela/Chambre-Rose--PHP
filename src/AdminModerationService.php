<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class AdminModerationService
{
    private const STATUSES = ['OPEN', 'REVIEWING', 'RESOLVED', 'DISMISSED'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} */
    public function list(?string $status, int $page, int $pageSize): array
    {
        $normalizedStatus = $status === null || trim($status) === '' ? null : strtoupper(trim($status));
        if ($normalizedStatus !== null && !in_array($normalizedStatus, self::STATUSES, true)) {
            throw new ApiException(400, 'Invalid moderation status.');
        }
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $where = $normalizedStatus === null ? '' : ' WHERE r.status=:status';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM user_reports r' . $where);
        $normalizedStatus === null ? $count->execute() : $count->execute(['status' => $normalizedStatus]);

        $statement = $this->pdo->prepare(
            'SELECT r.id,r.reason,r.details,r.status,r.created_at,'
            . ' reporter.id AS reporter_id,reporter.email AS reporter_email,reporter.first_name AS reporter_first_name,reporter.last_name AS reporter_last_name,'
            . ' reported.id AS reported_id,reported.email AS reported_email,reported.first_name AS reported_first_name,reported.last_name AS reported_last_name '
            . 'FROM user_reports r JOIN users reporter ON reporter.id=r.reporter_id '
            . 'JOIN users reported ON reported.id=r.reported_id' . $where
            . ' ORDER BY r.created_at DESC,r.id DESC LIMIT :limit OFFSET :offset'
        );
        if ($normalizedStatus !== null) $statement->bindValue(':status', $normalizedStatus);
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map([self::class, 'map'], $statement->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /** @return array<string, mixed> */
    public function updateStatus(int $id, string $status): array
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, self::STATUSES, true)) throw new ApiException(400, 'Invalid moderation status.');
        $statement = $this->pdo->prepare('UPDATE user_reports SET status=:status WHERE id=:id');
        $statement->execute(['status' => $status, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT 1 FROM user_reports WHERE id=:id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) throw new ApiException(404, 'Report not found.');
        }
        $item = $this->find($id);
        if ($item === null) throw new ApiException(404, 'Report not found.');

        return $item;
    }

    /** @return array<string, mixed>|null */
    private function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id,r.reason,r.details,r.status,r.created_at,'
            . ' reporter.id AS reporter_id,reporter.email AS reporter_email,reporter.first_name AS reporter_first_name,reporter.last_name AS reporter_last_name,'
            . ' reported.id AS reported_id,reported.email AS reported_email,reported.first_name AS reported_first_name,reported.last_name AS reported_last_name '
            . 'FROM user_reports r JOIN users reporter ON reporter.id=r.reporter_id '
            . 'JOIN users reported ON reported.id=r.reported_id WHERE r.id=:id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'reason' => (string) $row['reason'],
            'details' => $row['details'] === null ? null : (string) $row['details'],
            'status' => (string) $row['status'],
            'createdAt' => (string) $row['created_at'],
            'reporter' => [
                'id' => (int) $row['reporter_id'],
                'email' => (string) $row['reporter_email'],
                'name' => trim((string) $row['reporter_first_name'] . ' ' . (string) $row['reporter_last_name']),
            ],
            'reported' => [
                'id' => (int) $row['reported_id'],
                'email' => (string) $row['reported_email'],
                'name' => trim((string) $row['reported_first_name'] . ' ' . (string) $row['reported_last_name']),
            ],
        ];
    }
}
