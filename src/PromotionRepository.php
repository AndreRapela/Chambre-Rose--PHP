<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class PromotionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT slot,label,title,subtitle,link_url,link_text,icon,image_path,'
            . 'image_data IS NOT NULL AS has_image,updated_at FROM site_promotions ORDER BY slot'
        )->fetchAll();

        return array_map([self::class, 'map'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $slot): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT slot,label,title,subtitle,link_url,link_text,icon,image_path,'
            . 'image_data IS NOT NULL AS has_image,updated_at FROM site_promotions WHERE slot=:slot'
        );
        $statement->execute(['slot' => $slot]);
        $row = $statement->fetch();

        return is_array($row) ? self::map($row) : null;
    }

    /**
     * @param array{label:string,title:string,subtitle:string,linkUrl:string,linkText:string,icon:string} $data
     * @param array{name:string,contentType:string,bytes:string}|null $image
     * @return array<string, mixed>
     */
    public function update(int $slot, array $data, ?array $image): array
    {
        $timestamp = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'CURRENT_TIMESTAMP(3)'
            : 'CURRENT_TIMESTAMP';
        $imageSql = $image === null ? '' : ',image_file_name=:image_name,image_content_type=:image_type,'
            . 'image_size_bytes=:image_size,image_data=:image_data,image_path=NULL';
        $statement = $this->pdo->prepare(
            'UPDATE site_promotions SET label=:label,title=:title,subtitle=:subtitle,'
            . 'link_url=:link_url,link_text=:link_text,icon=:icon' . $imageSql
            . ',updated_at=' . $timestamp . ' WHERE slot=:slot'
        );
        $statement->bindValue(':slot', $slot, PDO::PARAM_INT);
        $statement->bindValue(':label', $data['label']);
        $statement->bindValue(':title', $data['title']);
        $statement->bindValue(':subtitle', $data['subtitle']);
        $statement->bindValue(':link_url', $data['linkUrl']);
        $statement->bindValue(':link_text', $data['linkText']);
        $statement->bindValue(':icon', $data['icon']);
        if ($image !== null) {
            $statement->bindValue(':image_name', $image['name']);
            $statement->bindValue(':image_type', $image['contentType']);
            $statement->bindValue(':image_size', strlen($image['bytes']), PDO::PARAM_INT);
            $statement->bindValue(':image_data', $image['bytes'], PDO::PARAM_LOB);
        }
        $statement->execute();
        if ($statement->rowCount() < 1 && $this->find($slot) === null) {
            throw new ApiException(404, 'Promotion not found.');
        }

        return $this->find($slot) ?? throw new ApiException(404, 'Promotion not found.');
    }

    /** @return array{contentType:string,size:int,bytes:string,updatedAt:string}|null */
    public function image(int $slot): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT image_content_type,image_size_bytes,image_data,updated_at FROM site_promotions WHERE slot=:slot'
        );
        $statement->execute(['slot' => $slot]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $bytes = $row['image_data'] ?? null;
        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        return [
            'contentType' => (string) $row['image_content_type'],
            'size' => (int) $row['image_size_bytes'],
            'bytes' => $bytes,
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        $slot = (int) $row['slot'];
        $updatedAt = (string) $row['updated_at'];
        $hasImage = in_array($row['has_image'], [true, 1, '1', 't', 'true'], true);

        return [
            'slot' => $slot,
            'label' => (string) $row['label'],
            'title' => (string) $row['title'],
            'subtitle' => (string) $row['subtitle'],
            'linkUrl' => (string) $row['link_url'],
            'linkText' => (string) $row['link_text'],
            'icon' => (string) $row['icon'],
            'imageUrl' => $hasImage
                ? '/api/promotions/' . $slot . '/image?v=' . rawurlencode($updatedAt)
                : (string) ($row['image_path'] ?? ''),
            'updatedAt' => $updatedAt,
        ];
    }
}
