<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class Seeder
{
    private readonly ProductRepository $products;

    public function __construct(private readonly PDO $pdo)
    {
        $this->products = new ProductRepository($pdo);
    }

    public function run(): void
    {
        if (!Config::bool('SEED_PRODUCTS_FORCE_REFRESH', false) && $this->products->count() > 0) {
            return;
        }
        foreach ($this->seeds() as $seed) {
            $existing = $this->products->findByName($seed['name']);
            $product = $existing === null ? $this->products->create($seed) : $this->products->update($existing['id'], $seed);
            $this->products->upsertImage($product['id'], 'MAIN', $this->slug($seed['name']) . '.svg',
                'image/svg+xml', $this->svg($seed['name'], $seed['color']));
            $product['imageUrl'] = "/api/products/{$product['id']}/images/main";
            $this->products->update($product['id'], $product);
        }
    }

    /** @return list<array<string, mixed>> */
    private function seeds(): array
    {
        $items = [
            ['Set Satin Rouge','femmes',89,99,'new','10% off','#7a102d'],
            ['Black Luxe Bodysuit','fetiches',109,123,'top seller','11% off','#211820'],
            ['Rose Duo Set','couples',129,139,'duo','7% off','#c43b5f'],
            ['Conjunto Basic Black','femmes',89,99,'new','10% off','#17151a'],
            ['Pink Lace Tie Brief','femmes',59,69,'fresh','14% off','#e87899'],
            ['Tanga Pink Ring','femmes',69,79,'style','13% off','#d94e79'],
            ['Night Men Collection','homme',79,89,'style','11% off','#273149'],
            ['Velvet Intimate Set','fetiches',119,135,'new','12% off','#61243d'],
            ['Satin Night','femmes',69,79,'fresh','13% off','#a33d63'],
        ];
        return array_map(function (array $item): array {
            [$name,$category,$price,$original,$tag,$sale,$color] = $item;
            return ['name'=>$name,'category'=>$category,'price'=>$price,'originalPrice'=>$original,
                'imageUrl'=>'pending-seed-image','secondaryImageUrl'=>null,'tag'=>$tag,'saleLabel'=>$sale,
                'rating'=>0,'reviews'=>0,'purchaseCount'=>0,'description'=>'Premium Chambre Rose selection.',
                'storeName'=>'Chambre Rose','storeAddress'=>'','storeCity'=>'','storeSegment'=>'Lingerie',
                'storeHours'=>'','productType'=>'Lingerie','material'=>'Premium lace and soft fabric',
                'availableSizes'=>'XS, S, M, L and XL','colorOptions'=>'See available options',
                'stockStatus'=>'Available','shippingNote'=>'Discreet packaging','careInstructions'=>'Hand wash',
                'color'=>$color];
        }, $items);
    }

    private function slug(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
    }

    private function svg(string $name, string $color): string
    {
        $title = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 1000" role="img" aria-label="{$title}">
              <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="{$color}"/><stop offset="1" stop-color="#f1a5ba"/></linearGradient></defs>
              <rect width="800" height="1000" fill="url(#g)"/><path d="M250 250h300l55 100-95 275H290l-95-275z" fill="none" stroke="#fff" stroke-width="22" opacity=".8"/>
              <text x="60" y="865" fill="#fff" font-family="Georgia,serif" font-size="58">{$title}</text>
              <text x="60" y="925" fill="#fff" font-family="Arial,sans-serif" font-size="28">Chambre Rose</text>
            </svg>
            SVG;
    }
}

