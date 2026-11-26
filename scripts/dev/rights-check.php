<?php declare(strict_types=1);
/**
 * The mail import checks its own right (D-1585 step 3, D-1586): its tab, its acts and the error detail need configure, and no
 * old right is left.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.237 active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;
use TaxMail\WordPress\Plugin;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

$old = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $old += preg_match_all('/\'manage_options\'|\bModeller::CAPABILITY\b|Taxmod\\\\WordPress\\\\Plugin::CAPABILITY\b/', (string) file_get_contents($file->getPathname()));
    }
}
$pruefe($old === 0, "no old right left in src ($old)");
$pruefe(Plugin::CAPABILITY === Rights::CONFIGURE, 'the mail import asks configure');

Rights::ensureRoles();
$buyer = (int) wp_insert_user(['user_login' => '__mlr_buyer', 'user_pass' => wp_generate_password(), 'role' => 'taxmod_purchase']);
$admin = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$pruefe(! user_can($buyer, Plugin::CAPABILITY) && user_can($admin, Plugin::CAPABILITY), 'purchasing does not set the import up, the administrator does');

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
