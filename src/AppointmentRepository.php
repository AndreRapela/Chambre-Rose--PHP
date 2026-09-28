<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AppointmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $escortUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,escort_user_id,title,client_name,starts_at,ends_at,location,notes,status,created_at,updated_at '
            . 'FROM escort_appointments WHERE escort_user_id=:escort_user_id '
            . 'ORDER BY starts_at ASC,id ASC'
        );
        $statement->execute(['escort_user_id' => $escortUserId]);

        return array_map([self::class, 'map'], $statement->fetchAll());
    }

    /** @param array<string,string|null> $data
     *  @return array<string,mixed>
     */
    public function create(int $escortUserId, array $data): array
    {
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(
                'INSERT INTO escort_appointments '
                . '(escort_user_id,title,client_name,starts_at,ends_at,location,notes,status,created_at,updated_at) '
                . 'VALUES (:escort_user_id,:title,:client_name,:starts_at,:ends_at,:location,:notes,:status,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))'
            );
            $statement->execute(['escort_user_id' => $escortUserId] + $data);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO escort_appointments '
                . '(escort_user_id,title,client_name,starts_at,ends_at,location,notes,status,created_at,updated_at) '
                . 'VALUES (:escort_user_id,:title,:client_name,:starts_at,:ends_at,:location,:notes,:status,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id'
            );
            $statement->execute(['escort_user_id' => $escortUserId] + $data);
            $id = (int) $statement->fetchColumn();
        }

        return $this->findOwned($id, $escortUserId);
    }

    /** @param array<string,string|null> $data
     *  @return array<string,mixed>
     */
    public function update(int $id, int $escortUserId, array $data): array
    {
        $statement = $this->pdo->prepare(
            'UPDATE escort_appointments SET title=:title,client_name=:client_name,starts_at=:starts_at,'
            . 'ends_at=:ends_at,location=:location,notes=:notes,status=:status,updated_at=CURRENT_TIMESTAMP '
            . 'WHERE id=:id AND escort_user_id=:escort_user_id'
        );
        $statement->execute($data + ['id' => $id, 'escort_user_id' => $escortUserId]);
        if ($statement->rowCount() === 0) {
            $this->findOwned($id, $escortUserId);
        }

        return $this->findOwned($id, $escortUserId);
    }

    public function delete(int $id, int $escortUserId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM escort_appointments WHERE id=:id AND escort_user_id=:escort_user_id'
        );
        $statement->execute(['id' => $id, 'escort_user_id' => $escortUserId]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'Appointment not found.');
        }
    }

    /** @return array<string,mixed> */
    private function findOwned(int $id, int $escortUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,escort_user_id,title,client_name,starts_at,ends_at,location,notes,status,created_at,updated_at '
            . 'FROM escort_appointments WHERE id=:id AND escort_user_id=:escort_user_id'
        );
        $statement->execute(['id' => $id, 'escort_user_id' => $escortUserId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'Appointment not found.');
        }

        return self::map($row);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private static function map(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'escortUserId' => (int) $row['escort_user_id'],
            'title' => (string) $row['title'],
            'clientName' => (string) $row['client_name'],
            'startsAt' => self::utc((string) $row['starts_at']),
            'endsAt' => self::utc((string) $row['ends_at']),
            'location' => $row['location'] === null ? null : (string) $row['location'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'status' => (string) $row['status'],
            'createdAt' => self::utc((string) $row['created_at']),
            'updatedAt' => self::utc((string) $row['updated_at']),
        ];
    }

    private static function utc(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
