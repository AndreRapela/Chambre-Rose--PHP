<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli' || ChambreRose\Config::get('APP_ENV') !== 'development') {
    throw new RuntimeException('Only available in local development.');
}
$pdo = ChambreRose\Database::connection();
$profiles = $pdo->query("SELECT u.id,u.email,p.display_name FROM users u JOIN professional_profiles p ON p.user_id=u.id WHERE p.profile_type='ESCORT' AND u.email LIKE '%@chambre-rose.invalid' ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
if (!in_array('--apply', $argv, true)) {
    echo json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}
$pdo->beginTransaction();
try {
    $vip = $pdo->prepare('UPDATE users SET vip_active=:vip WHERE id=:id');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM profile_reviews WHERE profile_user_id=:id AND reviewer_name=:name');
    $insert = $pdo->prepare('INSERT INTO profile_reviews (profile_user_id,reviewer_name,rating,body,created_at) VALUES (:id,:name,:rating,:body,CURRENT_TIMESTAMP)');
    foreach ($profiles as $index => $profile) {
        $vip->execute(['vip' => $index % 2 === 0 ? 1 : 0, 'id' => $profile['id']]);
        foreach ([
            ['Alex · Demo', 5, '[Demo review — not a real experience] Clear presentation and an easy-to-read profile.'],
            ['Camille · Demo', 4, '[Demo review — not a real experience] Helpful profile information. More availability details would be welcome.'],
            ['Sam · Demo', 5, '[Demo review — not a real experience] A well-organized gallery and useful information.']
        ] as [$name, $rating, $body]) {
            $exists->execute(['id' => $profile['id'], 'name' => $name]);
            if (!$exists->fetchColumn()) $insert->execute(['id' => $profile['id'], 'name' => $name, 'rating' => $rating, 'body' => $body]);
        }
    }
    $pdo->commit();
    echo count($profiles) . " existing demo profiles updated; no profiles created.\n";
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
