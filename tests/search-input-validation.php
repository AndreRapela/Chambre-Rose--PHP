<?php

declare(strict_types=1);

use ChambreRose\MarketplaceService;
use ChambreRose\ProductRepository;
use ChambreRose\ProfessionalProfileRepository;
use ChambreRose\ProfileMediaRepository;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\SearchPagination;
use ChambreRose\UserRepository;

final class SearchInputTestStatement extends PDOStatement
{
    /** @var array<string, mixed> */
    public array $bindings = [];

    protected function __construct()
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[(string) $param] = $value;
        return parent::bindValue($param, $value, $type);
    }
}

final class SearchInputTestPdo extends PDO
{
    /** @var list<SearchInputTestStatement> */
    public array $statements = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = parent::prepare($query, $options);
        if ($statement instanceof SearchInputTestStatement) $this->statements[] = $statement;
        return $statement;
    }

    public function lastStatement(): SearchInputTestStatement
    {
        return $this->statements[array_key_last($this->statements)]
            ?? throw new RuntimeException('The search did not prepare a query.');
    }
}

/** @param callable(bool, string): void $assert */
function runSearchInputChecks(callable $assert): void
{
    $pdo = new SearchInputTestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SearchInputTestStatement::class, []]);
    $pdo->exec(<<<'SQL'
        CREATE TABLE users (id INTEGER PRIMARY KEY,role TEXT,approval_status TEXT,vip_active INTEGER,city TEXT,country TEXT);
        CREATE TABLE professional_profiles (
          user_id INTEGER PRIMARY KEY,profile_type TEXT,created_at TEXT,updated_at TEXT,
          price_from REAL,price_to REAL,display_name TEXT,gender TEXT,location_city TEXT,
          location_region TEXT,location_country TEXT,bio TEXT,services TEXT,segment TEXT,
          purchase_count INTEGER DEFAULT 0,verified INTEGER DEFAULT 0
        );
        CREATE TABLE profile_media (user_id INTEGER,media_type TEXT);
        CREATE TABLE user_exclusions (owner_id INTEGER,excluded_user_id INTEGER);
        CREATE TABLE products (
          id INTEGER PRIMARY KEY,store_user_id INTEGER,name TEXT,category TEXT,price REAL,
          original_price REAL,image_url TEXT,secondary_image_url TEXT,tag TEXT,sale_label TEXT,
          reviews INTEGER,purchase_count INTEGER,likes INTEGER,description TEXT,store_name TEXT,
          store_address TEXT,store_city TEXT,store_segment TEXT,store_hours TEXT,product_type TEXT,
          material TEXT,available_sizes TEXT,color_options TEXT,stock_status TEXT,shipping_note TEXT,
          care_instructions TEXT,is_active INTEGER,created_at TEXT,updated_at TEXT
        );
        SQL);
    $profiles = new ProfessionalProfileRepository($pdo);
    $products = new ProductRepository($pdo);

    $profiles->search(['sort' => 'newest']);
    $assert(str_contains($pdo->lastStatement()->queryString, 'p.created_at DESC'), 'Valid profile sorting must retain the newest-first order.');
    $products->search(['sort' => 'priceAsc']);
    $assert(str_contains($pdo->lastStatement()->queryString, 'price ASC, id DESC'), 'Valid product sorting must retain its price order.');

    $profiles->search(['_viewerId' => '71']);
    $assert(!array_key_exists(':viewer_owner', $pdo->lastStatement()->bindings),
        'Public filters must not impersonate a viewer to inspect private exclusions.');
    $profiles->search(['_viewerId' => '99'], 71);
    $assert(($pdo->lastStatement()->bindings[':viewer_owner'] ?? null) === 71
        && ($pdo->lastStatement()->bindings[':viewer_target'] ?? null) === 71,
        'Exclusion filtering must use the separately supplied trusted viewer, never a public filter.');

    $marketplace = new MarketplaceService(
        new UserRepository($pdo),
        $profiles,
        new ProfileMediaRepository($pdo),
        new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($pdo))
    );
    $marketplace->listings(['_viewerId' => '71']);
    $assert(!array_key_exists(':viewer_owner', $pdo->lastStatement()->bindings),
        'Anonymous marketplace requests must not acquire a viewer from query parameters.');
    $marketplace->listings(['_viewerId' => '99'], ['id' => 71]);
    $assert(($pdo->lastStatement()->bindings[':viewer_owner'] ?? null) === 71,
        'The marketplace must pass the authenticated viewer separately to the search repository.');

    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO users (id,role,approval_status,vip_active) VALUES
            (71,'VISITOR','APPROVED',0),(72,'ESCORT','APPROVED',0),(73,'ESCORT','APPROVED',0)");
        $pdo->exec("INSERT INTO professional_profiles (user_id,profile_type,display_name) VALUES
            (72,'ESCORT','Excluded profile'),(73,'ESCORT','Visible profile')");
        $pdo->exec('INSERT INTO user_exclusions (owner_id,excluded_user_id) VALUES (71,72)');
        $anonymous = $profiles->search(['_viewerId' => '71']);
        $anonymousIds = array_column($anonymous['items'], 'userId');
        sort($anonymousIds);
        $assert($anonymousIds === [72, 73] && $anonymous['total'] === 2,
            'Anonymous results must not disclose another viewer\'s private exclusions.');
        $authenticated = $profiles->search(['_viewerId' => '99'], 71);
        $assert(array_column($authenticated['items'], 'userId') === [73] && $authenticated['total'] === 1,
            'Authenticated results must still honor the viewer\'s exclusions.');
        $pdo->exec('DELETE FROM user_exclusions');
        $pdo->exec('INSERT INTO user_exclusions (owner_id,excluded_user_id) VALUES (72,71)');
        $reverse = $profiles->search([], 71);
        $assert(array_column($reverse['items'], 'userId') === [73],
            'Authenticated results must also honor exclusions created by the profile owner.');
    } finally {
        $pdo->rollBack();
    }

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        foreach ([['newest'], ['nested' => ['newest']]] as $sort) {
            $profiles->search(['sort' => $sort]);
            $assert(str_contains($pdo->lastStatement()->queryString, 'p.updated_at DESC'), 'Malformed profile sort values must use the default order.');
            $products->search(['sort' => $sort]);
            $assert(str_contains($pdo->lastStatement()->queryString, 'purchase_count DESC'), 'Malformed product sort values must use the default order.');
        }
    } finally {
        restore_error_handler();
    }
    $assert($warnings === [], 'Array-valued search parameters must not produce PHP conversion warnings.');

    foreach (['minPrice', 'maxPrice'] as $field) {
        foreach (['1e309', '-1e309'] as $invalid) {
            $profiles->search([$field => $invalid]);
            $assert(!array_key_exists(':' . $field, $pdo->lastStatement()->bindings), 'Profile price filters must ignore nonfinite numbers.');
            $products->search([$field => $invalid]);
            $assert(!array_key_exists(':' . $field, $pdo->lastStatement()->bindings), 'Product price filters must ignore nonfinite numbers.');
        }
    }
    foreach ([$profiles, $products] as $repository) {
        $repository->search(['minPrice' => '12.50', 'maxPrice' => '50.00']);
        $assert($pdo->lastStatement()->bindings[':minPrice'] === 12.5 && $pdo->lastStatement()->bindings[':maxPrice'] === 50.0,
            'Valid decimal price filters must remain unchanged.');
    }

    $page = $profiles->search(['includeTotal' => false, 'page' => (string) PHP_INT_MAX, 'pageSize' => '50']);
    $offset = $pdo->lastStatement()->bindings[':offset'];
    $assert(is_int($offset) && $offset >= 0, 'Uncounted searches must bind a nonnegative integer offset without overflow.');
    $assert($page['page'] === intdiv(PHP_INT_MAX, 50) + 1, 'Uncounted searches must bound the page to a representable offset.');
    $profiles->search(['includeTotal' => false, 'page' => (string) PHP_INT_MAX, 'pageSize' => '1']);
    $assert($pdo->lastStatement()->bindings[':offset'] === PHP_INT_MAX - 1, 'Representable large offsets must remain exact.');

    $page = $profiles->search(['includeTotal' => false, 'page' => ['2'], 'pageSize' => ['40']]);
    $assert($page['page'] === 1 && $page['pageSize'] === 20 && $pdo->lastStatement()->bindings[':offset'] === 0,
        'Uncounted searches must use safe defaults for nonscalar pagination.');
    $assert(SearchPagination::resolve(100, ['2'], ['40']) === ['page' => 1, 'pageSize' => 20, 'totalPages' => 5, 'offset' => 0],
        'Counted searches must use the same defaults for nonscalar pagination.');
    $assert(SearchPagination::resolve(100, 'invalid', 'invalid') === ['page' => 1, 'pageSize' => 20, 'totalPages' => 5, 'offset' => 0],
        'Invalid integer text must not become a page size of one.');
    $assert(SearchPagination::resolve(9, '2', '8') === ['page' => 2, 'pageSize' => 8, 'totalPages' => 2, 'offset' => 8],
        'Valid integer query strings must retain their pagination.');
    $assert(SearchPagination::resolve(PHP_INT_MAX, PHP_INT_MAX, 1) === ['page' => PHP_INT_MAX, 'pageSize' => 1, 'totalPages' => PHP_INT_MAX, 'offset' => PHP_INT_MAX - 1],
        'Pagination must calculate large totals without floating-point rounding.');
    $assert(SearchPagination::resolve(-100, 2, 20) === ['page' => 1, 'pageSize' => 20, 'totalPages' => 0, 'offset' => 0],
        'A negative total must not produce negative page counts.');
}
