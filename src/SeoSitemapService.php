<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use PDO;
use Throwable;

final class SeoSitemapService
{
    private readonly string $origin;

    public function __construct(private readonly PDO $pdo, ?string $origin = null)
    {
        $this->origin = self::normalizeOrigin(
            $origin ?? Config::get('SEO_CANONICAL_ORIGIN', 'https://www.chambre-rose.com') ?? ''
        );
    }

    public function xml(): string
    {
        $urls = [];

        $profiles = $this->pdo->query(
            "SELECT p.user_id,p.updated_at AS profile_updated_at,u.updated_at AS user_updated_at
             FROM professional_profiles p
             INNER JOIN users u ON u.id=p.user_id
             WHERE u.approval_status='APPROVED' AND u.role IN ('ESCORT','STORE')
             ORDER BY p.user_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($profiles as $profile) {
            $urls[] = [
                'loc' => '/catalogue/perfil/' . (int) $profile['user_id'],
                'lastmod' => self::latestDate(
                    (string) $profile['profile_updated_at'],
                    (string) $profile['user_updated_at']
                ),
            ];
        }

        $products = $this->pdo->query(
            'SELECT id,updated_at FROM products WHERE is_active=TRUE ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as $product) {
            $urls[] = [
                'loc' => '/catalogue/produto/' . (int) $product['id'],
                'lastmod' => self::date((string) $product['updated_at']),
            ];
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $loc = htmlspecialchars($this->origin . $url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= "  <url><loc>{$loc}</loc><lastmod>{$url['lastmod']}</lastmod></url>\n";
        }

        return $xml . "</urlset>\n";
    }

    private static function normalizeOrigin(string $origin): string
    {
        $origin = rtrim(trim($origin), '/');
        $parts = parse_url($origin);
        if ($parts === false
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new ApiException(500, 'SEO canonical origin is invalid.');
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
    }

    private static function latestDate(string $first, string $second): string
    {
        $firstTimestamp = strtotime($first) ?: 0;
        $secondTimestamp = strtotime($second) ?: 0;

        return self::date($firstTimestamp >= $secondTimestamp ? $first : $second);
    }

    private static function date(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return gmdate('Y-m-d');
        }
    }
}
