<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\Settings as CoreSettings;
use App\Core\Universe;
use App\Http\Response;

final class Settings
{
    public static function register(App $app, callable $render): void
    {
        // Settings (branding / identity)
        $app->router->get('/admin/settings', function () use ($app, $render): Response {
            $settings = new CoreSettings($app->db);

            $brandName = $settings->get('site.brand.name', 'killsineve.online') ?? 'killsineve.online';
            $type = $settings->get('site.identity.type', 'corporation') ?? 'corporation';
            $id = (int)($settings->get('site.identity.id', '0') ?? '0');

            // Build quick-pick options from logged-in character profile
            $options = [];
            $cid = (int)($_SESSION['character_id'] ?? 0);
            if ($cid > 0) {
                $u = new Universe($app->db);
                $p = $u->characterProfile($cid);

                if (!empty($p['corporation']['id'])) {
                    $label = (string)($p['corporation']['name'] ?? 'Corporation');
                    if (!empty($p['corporation']['ticker'])) $label .= " [" . (string)$p['corporation']['ticker'] . "]";
                    $options[] = ['type' => 'corporation', 'id' => (int)$p['corporation']['id'], 'label' => $label];
                }
                if (!empty($p['alliance']['id'])) {
                    $label = (string)($p['alliance']['name'] ?? 'Alliance');
                    if (!empty($p['alliance']['ticker'])) $label .= " [" . (string)$p['alliance']['ticker'] . "]";
                    $options[] = ['type' => 'alliance', 'id' => (int)$p['alliance']['id'], 'label' => $label];
                }
            }

            $h = "<h1>Settings</h1>
                  <p class='text-muted'>Control plane for site identity, branding and platform defaults.</p>

                  <div class='card'><div class='card-body'>
                    <form method='post' action='/admin/settings/save' class='row g-3'>

                      <div class='col-12 col-lg-6'>
                        <label class='form-label'>Site name</label>
                        <input class='form-control' name='site_brand_name' value='" . htmlspecialchars($brandName) . "'>
                        <div class='form-text'>Shown top-left and used as the platform brand label.</div>
                      </div>

                      <div class='col-12 col-lg-3'>
                        <label class='form-label'>Website type</label>
                        <select class='form-select' name='site_identity_type'>
                          <option value='corporation'" . ($type==='corporation'?' selected':'') . ">Corporation</option>
                          <option value='alliance'" . ($type==='alliance'?' selected':'') . ">Alliance</option>
                        </select>
                      </div>

                      <div class='col-12 col-lg-3'>
                        <label class='form-label'>Identity ID</label>
                        <input class='form-control' name='site_identity_id' value='" . htmlspecialchars((string)$id) . "'>
                        <div class='form-text'>Used for logo + favicon. Paste an EVE corp/alliance ID, or use quick pick.</div>
                      </div>";

            if (!empty($options)) {
                $h .= "<div class='col-12'>
                         <label class='form-label'>Quick pick (from your logged-in character)</label>
                         <div class='d-flex flex-wrap gap-2'>";
                foreach ($options as $o) {
                    $oid = (int)$o['id'];
                    $logo = ($o['type'] === 'alliance')
                        ? "https://images.evetech.net/alliances/{$oid}/logo?size=32"
                        : "https://images.evetech.net/corporations/{$oid}/logo?size=32";

                    $h .= "<button type='button' class='btn btn-outline-light btn-sm'
                                  onclick=\"document.querySelector('[name=site_identity_type]').value='" . htmlspecialchars($o['type']) . "';
                                           document.querySelector('[name=site_identity_id]').value='" . $oid . "';\">
                              <img src='" . htmlspecialchars($logo) . "' style='width:18px;height:18px;border-radius:5px;margin-right:6px;'>
                              " . htmlspecialchars((string)$o['label']) . "
                           </button>";
                }
                $h .= "   </div>
                       </div>";
            }

            $h .= "      <div class='col-12'>
                        <button class='btn btn-primary'>Save settings</button>
                      </div>

                    </form>
                  </div></div>";

            return $render('Settings', $h);
        }, ['right' => 'admin.settings']);

        $app->router->post('/admin/settings/save', function () use ($app): Response {
            $settings = new CoreSettings($app->db);

            $name = trim((string)($_POST['site_brand_name'] ?? 'killsineve.online'));
            $type = trim((string)($_POST['site_identity_type'] ?? 'corporation'));
            $id = trim((string)($_POST['site_identity_id'] ?? '0'));

            if ($name === '') $name = 'killsineve.online';
            if ($type !== 'corporation' && $type !== 'alliance') $type = 'corporation';
            if (!ctype_digit($id)) $id = '0';

            $settings->set('site.brand.name', $name);
            $settings->set('site.identity.type', $type);
            $settings->set('site.identity.id', $id);

            return Response::redirect('/admin/settings');
        }, ['right' => 'admin.settings']);
    }
}
