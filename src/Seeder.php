<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class Seeder
{
    private readonly UserRepository $users;
    private readonly ProfessionalProfileRepository $profiles;

    public function __construct(private readonly PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
        $this->profiles = new ProfessionalProfileRepository($pdo);
    }

    public function run(): void
    {
        if (Config::bool('SEED_MVP_CONTENT', false)) {
            $storeId = $this->seedStore();
            $this->seedProducts($storeId);
            $this->seedCompanions();
        }
        if (!Config::bool('SEED_DEMO_USERS', false)) {
            return;
        }
        $this->createUserIfMissing(
            Config::get('SEED_USER_EMAIL', ''),
            Config::get('SEED_USER_PASSWORD'),
            'Visitor',
            'Account',
            'VISITOR'
        );
        $this->createUserIfMissing(
            Config::get('SEED_ADMIN_EMAIL', ''),
            Config::get('SEED_ADMIN_PASSWORD'),
            'Admin',
            'Chambre Rose',
            'ADMIN'
        );
    }

    private function seedStore(): int
    {
        $email = 'demo-boutique@chambre-rose.invalid';
        $user = $this->users->findByEmail($email) ?? $this->users->create([
            'firstName' => 'Maison', 'lastName' => 'Rose', 'email' => $email, 'phone' => '',
            'address' => '', 'city' => 'Brussels', 'country' => 'Belgium', 'postalCode' => '',
        ], password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 12]), 'STORE', 'APPROVED', 'fr');
        if ($this->profiles->findByUser((int) $user['id']) === null) {
            $this->profiles->upsert((int) $user['id'], 'STORE', [
                'displayName' => 'Maison Rose Intime',
                'location' => 'Brussels, Belgium',
                'bio' => 'Boutique fictive du MVP spécialisée dans le bien-être intime, la lingerie et les accessoires pour adultes.',
                'languages' => ['French', 'English'],
                'services' => ['Discreet delivery', 'Store pickup', 'Private advice'],
                'interests' => [], 'contactOptions' => ['Internal message'],
                'availability' => 'Monday to Saturday, 10:00–20:00', 'website' => null,
                'priceFrom' => 19, 'priceTo' => 189, 'businessName' => 'Maison Rose Intime',
                'legalName' => 'MVP demonstration account', 'segment' => 'Sex shop & intimate wellness',
                'businessAddress' => 'Brussels, Belgium', 'businessHours' => 'Monday to Saturday, 10:00–20:00',
                'birthDate' => null, 'gender' => null, 'heightCm' => null, 'weightKg' => null,
                'bustCm' => null, 'waistCm' => null, 'hipsCm' => null, 'hair' => null, 'eyes' => null, 'origin' => null,
            ]);
            $this->pdo->prepare('UPDATE professional_profiles SET purchase_count=10,views_count=428,verified=TRUE WHERE user_id=:id')
                ->execute(['id' => (int) $user['id']]);
        }

        return (int) $user['id'];
    }

    private function seedProducts(int $storeId): void
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) {
            $this->fillDemoProductGalleries();
            $this->seedProductReviews();
            return;
        }
        $products = [
            ['Set Satin Rouge', 'lingerie', 89, 99, 'carousel-pink-ring-thong.jpeg', 'new', '10% off', 10, 'Ensemble en satin et dentelle au fini délicat.'],
            ['Black Luxe Bodysuit', 'lingerie', 109, 123, 'carousel-basic-black.jpeg', 'top seller', '11% off', 10, 'Body noir structuré au style élégant et affirmé.'],
            ['Rose Duo Set', 'couples', 129, 139, 'carousel-pink-lace-tie.jpeg', 'duo', '7% off', 8, 'Ensemble coordonné pensé pour les couples.'],
            ['Basic Black Set', 'lingerie', 89, 99, 'carousel-basic-black.jpeg', 'new', '10% off', 10, 'Ensemble noir confortable, moderne et sensuel.'],
            ['Pink Lace Tie Brief', 'lingerie', 59, 69, 'carousel-pink-lace-tie.jpeg', 'fresh', '14% off', 7, 'Dentelle rose, volants souples et liens latéraux.'],
            ['Tanga Pink Ring', 'lingerie', 69, 79, 'carousel-pink-ring-thong.jpeg', 'style', '13% off', 9, 'Tanga rose avec dentelle florale et détail anneau.'],
            ['Couples Discovery Box', 'couples', 79, 89, 'carousel-pink-ring-thong.jpeg', 'popular', '11% off', 6, 'Sélection discrète d’accessoires pour découvrir à deux.'],
            ['Velvet Intimate Kit', 'toys', 119, 135, 'carousel-basic-black.jpeg', 'new', '12% off', 9, 'Coffret bien-être intime premium aux tons profonds.'],
            ['Massage & Care Set', 'wellness', 49, 59, 'carousel-pink-lace-tie.jpeg', 'fresh', '17% off', 5, 'Huiles et accessoires de massage dans un emballage discret.'],
        ];
        $sql = <<<'SQL'
            INSERT INTO products (
              store_user_id,name,category,price,original_price,image_url,secondary_image_url,
              tag,sale_label,reviews,purchase_count,likes,description,store_name,store_address,
              store_city,store_segment,store_hours,product_type,material,available_sizes,color_options,
              stock_status,shipping_note,care_instructions,is_active,created_at,updated_at
            ) VALUES (
              :store,:name,:category,:price,:original,:image,:secondary,:tag,:sale,:reviews,:purchases,0,
              :description,'Maison Rose Intime','Brussels, Belgium','Brussels','Sex shop & intimate wellness',
              'Monday to Saturday, 10:00–20:00',:type,'Premium selected materials','One size / multiple options',
              'Black, rose and neutral','In stock','Discreet neutral packaging','See product label',TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
            )
            SQL;
        $statement = $this->pdo->prepare($sql);
        $gallery = ['carousel-basic-black.jpeg', 'carousel-pink-lace-tie.jpeg', 'carousel-pink-ring-thong.jpeg'];
        foreach ($products as $index => [$name, $category, $price, $original, $image, $tag, $sale, $purchases, $description]) {
            $statement->execute([
                'store' => $storeId, 'name' => $name, 'category' => $category, 'price' => $price,
                'original' => $original, 'image' => '/assets/' . $image,
                'secondary' => '/assets/' . $gallery[($index + 1) % count($gallery)],
                'tag' => $tag, 'sale' => $sale,
                'reviews' => min(8, $purchases), 'purchases' => $purchases,
                'description' => $description, 'type' => ucfirst($category),
            ]);
        }
        $this->seedProductReviews();
    }

    private function fillDemoProductGalleries(): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE products SET secondary_image_url=:image WHERE store_name='Maison Rose Intime' AND secondary_image_url IS NULL"
        );
        $statement->execute(['image' => '/assets/carousel-pink-lace-tie.jpeg']);
    }

    private function seedProductReviews(): void
    {
        $products = $this->pdo->query(
            "SELECT id,name FROM products WHERE store_name='Maison Rose Intime' ORDER BY id"
        )->fetchAll();
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM product_reviews WHERE product_id=:product');
        $insert = $this->pdo->prepare(
            'INSERT INTO product_reviews (product_id,reviewer_name,body,created_at) VALUES (:product,:name,:body,CURRENT_TIMESTAMP)'
        );
        $reviews = [
            ['Alexandre', 'Produit conforme à la description, emballage discret et livraison soignée.'],
            ['Camille', 'Belle finition et accompagnement professionnel de la boutique.'],
            ['Morgan', 'Commande simple, présentation claire et service très attentionné.'],
        ];
        foreach ($products as $product) {
            $count->execute(['product' => (int) $product['id']]);
            if ((int) $count->fetchColumn() > 0) {
                continue;
            }
            foreach ($reviews as [$reviewer, $body]) {
                $insert->execute([
                    'product' => (int) $product['id'],
                    'name' => $reviewer,
                    'body' => $body,
                ]);
            }
        }
    }

    private function seedCompanions(): void
    {
        $profiles = [
            ['Luna Douce', 'Paris, France', '1998-04-18', 168, 54, 'Brown', 'Hazel', 'French', 10, 458, 'carousel-basic-black.jpeg'],
            ['Emma Charm', 'Lyon, France', '1996-09-03', 171, 58, 'Black', 'Brown', 'Belgian', 9, 371, 'carousel-pink-lace-tie.jpeg'],
            ['Maya Sensuelle', 'Marseille, France', '1999-02-14', 165, 52, 'Brown', 'Green', 'French', 8, 296, 'carousel-pink-ring-thong.jpeg'],
            ['Nina Velvet', 'Toulouse, France', '1997-11-27', 169, 56, 'Blonde', 'Blue', 'European', 7, 241, 'carousel-basic-black.jpeg'],
            ['Sasha Luxury', 'Nice, France', '1995-06-09', 173, 60, 'Black', 'Brown', 'French', 6, 189, 'carousel-pink-ring-thong.jpeg'],
        ];
        foreach ($profiles as $index => [$name, $location, $birth, $height, $weight, $hair, $eyes, $origin, $purchases, $views, $image]) {
            $email = 'demo-companion-' . ($index + 1) . '@chambre-rose.invalid';
            $user = $this->users->findByEmail($email) ?? $this->users->create([
                'firstName' => $name, 'lastName' => '', 'email' => $email, 'phone' => '', 'address' => '',
                'city' => explode(',', $location)[0], 'country' => 'France', 'postalCode' => '',
            ], password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 12]), 'ESCORT', 'APPROVED', 'fr');
            $userId = (int) $user['id'];
            if ($this->profiles->findByUser($userId) === null) {
                $this->profiles->upsert($userId, 'ESCORT', [
                    'displayName' => $name, 'birthDate' => $birth, 'gender' => 'WOMAN', 'location' => $location,
                    'bio' => 'Profil fictif de démonstration du MVP. Une présence élégante, attentive et discrète pour des échanges respectueux.',
                    'languages' => ['French', 'English'], 'heightCm' => $height, 'weightKg' => $weight,
                    'bustCm' => 90, 'waistCm' => 60, 'hipsCm' => 90, 'hair' => $hair, 'eyes' => $eyes, 'origin' => $origin,
                    'services' => ['Private message', 'Personalized photos', 'Video call'],
                    'interests' => ['Fine lingerie', 'Travel', 'Photography', 'Conversation'],
                    'contactOptions' => ['Private message|0', 'Personalized photos|20', 'Short custom video|35', 'Video call (15 min)|45'],
                    'availability' => 'Online today', 'website' => null, 'priceFrom' => 89, 'priceTo' => 189,
                    'businessName' => null, 'legalName' => null, 'segment' => null, 'businessAddress' => null, 'businessHours' => null,
                ]);
                $this->pdo->prepare('UPDATE professional_profiles SET purchase_count=:purchases,views_count=:views,verified=TRUE WHERE user_id=:id')
                    ->execute(['id' => $userId, 'purchases' => $purchases, 'views' => $views]);
                $gallery = array_values(array_unique([
                    $image,
                    'carousel-basic-black.jpeg',
                    'carousel-pink-lace-tie.jpeg',
                    'carousel-pink-ring-thong.jpeg',
                ]));
                $this->seedProfilePhotos($userId, $gallery);
                $this->seedReviews($userId, $name);
                $this->pdo->prepare('UPDATE users SET vip_active=TRUE,vip_since=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $userId]);
            }
        }
    }

    /** @param list<string> $fileNames */
    private function seedProfilePhotos(int $userId, array $fileNames): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:user,'PHOTO',:name,'image/jpeg',:size,:data,:position,CURRENT_TIMESTAMP)"
        );
        foreach ($fileNames as $position => $fileName) {
            $path = dirname(__DIR__) . '/resources/seed-images/' . $fileName;
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false) {
                continue;
            }
            $statement->execute([
                'user' => $userId,
                'name' => $fileName,
                'size' => strlen($bytes),
                'data' => $bytes,
                'position' => $position,
            ]);
        }
    }

    private function seedReviews(int $userId, string $name): void
    {
        $reviews = [
            ['Alexandre', "{$name} est attentive et très professionnelle. Une expérience discrète et agréable."],
            ['Marc', 'Échanges très agréables, réponse rapide et profil conforme.'],
            ['Julien', 'Une vraie attention aux détails et beaucoup de respect.'],
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO profile_reviews (profile_user_id,reviewer_name,body,created_at) VALUES (:profile,:name,:body,CURRENT_TIMESTAMP)'
        );
        foreach ($reviews as [$reviewer, $body]) {
            $statement->execute(['profile' => $userId, 'name' => $reviewer, 'body' => $body]);
        }
    }

    private function createUserIfMissing(?string $email, ?string $password, string $first, string $last, string $role): void
    {
        $email = strtolower(trim($email ?? ''));
        if ($email === '' || $password === null || $password === '' || $this->users->findByEmail($email) !== null) {
            return;
        }
        $this->users->create([
            'firstName' => $first, 'lastName' => $last, 'email' => $email, 'phone' => '', 'address' => '',
            'city' => '', 'country' => '', 'postalCode' => '',
        ], password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $role);
    }
}
