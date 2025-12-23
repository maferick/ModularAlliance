<?php
declare(strict_types=1);

/*
Module Name: Character Linker
Description: Link multiple EVE characters to a single user account.
Version: 1.0.0
Module Slug: charlink
*/

use App\Core\Layout;
use App\Core\ModuleRegistry;
use App\Core\Rights;
use App\Core\Universe;
use App\Http\Request;
use App\Http\Response;

return function (ModuleRegistry $registry): void {
    $app = $registry->app();

    $registry->right('charlink.admin', 'Manage character links and link tokens.');

    $registry->menu([
        'slug' => 'charlink',
        'title' => 'Linked Characters',
        'url' => '/user/alts',
        'sort_order' => 25,
        'area' => 'left',
    ]);

    $registry->menu([
        'slug' => 'admin.charlink',
        'title' => 'Character Linker',
        'url' => '/admin/charlink',
        'sort_order' => 35,
        'area' => 'admin_top',
        'right_slug' => 'charlink.admin',
    ]);

    $renderPage = function (string $title, string $bodyHtml) use ($app): string {
        $rights = new Rights($app->db);
        $hasRight = function (string $right) use ($rights): bool {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid <= 0) return false;
            return $rights->userHasRight($uid, $right);
        };

        $leftTree = $app->menu->tree('left', $hasRight);
        $adminTree = $app->menu->tree('admin_top', $hasRight);
        $userTree = $app->menu->tree('user_top', fn(string $r) => true);

        $loggedIn = ((int)($_SESSION['character_id'] ?? 0) > 0);
        if ($loggedIn) {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] !== 'user.login'));
        } else {
            $userTree = array_values(array_filter($userTree, fn($n) => $n['slug'] === 'user.login'));
        }

        return Layout::page($title, $bodyHtml, $leftTree, $adminTree, $userTree);
    };

    $registry->route('GET', '/charlink/activate', function (Request $req) use ($renderPage): Response {
        $token = $req->query['token'] ?? '';
        if (!is_string($token) || $token === '') {
            $body = "<h1>Character Linker</h1>
                     <div class='alert alert-warning mt-3'>Missing link token. Ask your admin or main character to generate a new link token.</div>";
            return Response::html($renderPage('Character Linker', $body), 200);
        }

        $_SESSION['charlink_token'] = $token;
        return Response::redirect('/auth/login');
    }, ['public' => true]);

    $registry->route('POST', '/user/alts/token', function () use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 8);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

        $app->db->run(
            "INSERT INTO character_link_tokens (user_id, token_hash, token_prefix, expires_at)
             VALUES (?, ?, ?, ?)",
            [$uid, $tokenHash, $tokenPrefix, $expiresAt]
        );

        $_SESSION['charlink_new_token'] = [
            'token' => $rawToken,
            'expires_at' => $expiresAt,
        ];

        return Response::redirect('/user/alts');
    });

    $registry->route('POST', '/user/alts/revoke', function (Request $req) use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $characterId = (int)($req->post['character_id'] ?? 0);
        if ($characterId > 0) {
            $app->db->run(
                "UPDATE character_links
                 SET status='revoked', revoked_at=NOW(), revoked_by_user_id=?
                 WHERE user_id=? AND character_id=?",
                [$uid, $uid, $characterId]
            );
        }

        return Response::redirect('/user/alts');
    });

    $registry->route('POST', '/user/alts/token-delete', function (Request $req) use ($app): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $tokenId = (int)($req->post['token_id'] ?? 0);
        if ($tokenId > 0) {
            $app->db->run(
                "DELETE FROM character_link_tokens WHERE id=? AND user_id=?",
                [$tokenId, $uid]
            );
        }

        return Response::redirect('/user/alts');
    });

    $registry->route('GET', '/user/alts', function () use ($app, $renderPage): Response {
        $cid = (int)($_SESSION['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($cid <= 0 || $uid <= 0) return Response::redirect('/auth/login');

        $flash = $_SESSION['charlink_flash'] ?? null;
        unset($_SESSION['charlink_flash']);
        $newToken = $_SESSION['charlink_new_token'] ?? null;
        unset($_SESSION['charlink_new_token']);

        $primary = $app->db->one("SELECT id, character_id, character_name FROM eve_users WHERE id=? LIMIT 1", [$uid]);
        $links = $app->db->all(
            "SELECT character_id, character_name, linked_at
             FROM character_links
             WHERE user_id=? AND status='linked'
             ORDER BY linked_at ASC",
            [$uid]
        );
        $tokens = $app->db->all(
            "SELECT id, token_prefix, expires_at, used_at, used_character_id
             FROM character_link_tokens
             WHERE user_id=?
             ORDER BY created_at DESC
             LIMIT 50",
            [$uid]
        );

        $u = new Universe($app->db);
        $getPortrait = function (int $characterId) use ($u): ?string {
            $profile = $u->characterProfile($characterId);
            return $profile['character']['portrait']['px128x128']
                ?? $profile['character']['portrait']['px64x64']
                ?? null;
        };

        $cards = '';
        if ($primary && isset($primary['character_id'])) {
            $primaryId = (int)$primary['character_id'];
            $primaryName = htmlspecialchars((string)($primary['character_name'] ?? 'Unknown'));
            $portrait = $getPortrait($primaryId);
            $badge = $primaryId === $cid ? "<span class='badge bg-success ms-2'>Current</span>" : "<span class='badge bg-primary ms-2'>Main</span>";

            $cards .= "<div class='col-md-6'>
                <div class='card card-body'>
                  <div class='d-flex align-items-center gap-3'>";
            if ($portrait) {
                $cards .= "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:12px;'>";
            }
            $cards .= "<div>
                    <div class='fw-semibold'>{$primaryName}{$badge}</div>
                    <div class='text-muted small'>Character ID: {$primaryId}</div>
                  </div>
                </div>
              </div>
            </div>";
        }

        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            if ($linkId <= 0) continue;
            $linkName = htmlspecialchars((string)($link['character_name'] ?? 'Unknown'));
            $portrait = $getPortrait($linkId);
            $isCurrent = $linkId === $cid;
            $badge = $isCurrent ? "<span class='badge bg-success ms-2'>Current</span>" : '';
            $linkedAt = htmlspecialchars((string)($link['linked_at'] ?? ''));

            $cards .= "<div class='col-md-6'>
                <div class='card card-body'>
                  <div class='d-flex align-items-center justify-content-between gap-3'>
                    <div class='d-flex align-items-center gap-3'>";
            if ($portrait) {
                $cards .= "<img src='" . htmlspecialchars($portrait) . "' width='64' height='64' style='border-radius:12px;'>";
            }
            $cards .= "<div>
                        <div class='fw-semibold'>{$linkName}{$badge}</div>
                        <div class='text-muted small'>Linked {$linkedAt}</div>
                      </div>
                    </div>
                    <form method='post' action='/user/alts/revoke' onsubmit=\"return confirm('Unlink {$linkName}?');\">
                      <input type='hidden' name='character_id' value='{$linkId}'>
                      <button class='btn btn-sm btn-outline-danger'>Unlink</button>
                    </form>
                  </div>
                </div>
              </div>";
        }

        if ($cards === '') {
            $cards = "<div class='col-12'><div class='text-muted'>No linked characters yet.</div></div>";
        }

        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $tokenHtml = '';
        if (is_array($newToken)) {
            $token = htmlspecialchars((string)($newToken['token'] ?? ''));
            $expiresAt = htmlspecialchars((string)($newToken['expires_at'] ?? ''));
            if ($token !== '') {
                $linkUrl = '/charlink/activate?token=' . urlencode((string)($newToken['token'] ?? ''));
                $tokenHtml = "<div class='alert alert-info'>
                    <div class='fw-semibold'>New link token</div>
                    <div class='mt-2'><code>{$token}</code></div>
                    <div class='small text-muted mt-1'>Expires at {$expiresAt} UTC. Share this with your alt.</div>
                    <div class='mt-2'><a class='btn btn-sm btn-primary' href='" . htmlspecialchars($linkUrl) . "'>Start linking</a></div>
                  </div>";
            }
        }

        $tokenRows = '';
        foreach ($tokens as $tokenRow) {
            $tokenId = (int)($tokenRow['id'] ?? 0);
            $prefix = htmlspecialchars((string)($tokenRow['token_prefix'] ?? ''));
            $expiresAt = htmlspecialchars((string)($tokenRow['expires_at'] ?? ''));
            $usedAt = htmlspecialchars((string)($tokenRow['used_at'] ?? ''));
            $usedChar = htmlspecialchars((string)($tokenRow['used_character_id'] ?? ''));
            $status = $usedAt !== '' ? "Used ({$usedChar})" : 'Active';

            $tokenRows .= "<tr>
                <td>{$prefix}</td>
                <td>{$expiresAt}</td>
                <td>{$status}</td>
                <td>
                  <form method='post' action='/user/alts/token-delete' onsubmit=\"return confirm('Delete token {$prefix}?');\">
                    <input type='hidden' name='token_id' value='{$tokenId}'>
                    <button class='btn btn-sm btn-outline-secondary'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($tokenRows === '') {
            $tokenRows = "<tr><td colspan='4' class='text-muted'>No tokens issued.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-3'>
                    <div>
                      <h1 class='mb-1'>Linked Characters</h1>
                      <div class='text-muted'>Manage your alt characters and link new pilots.</div>
                    </div>
                    <form method='post' action='/user/alts/token'>
                      <button class='btn btn-primary'>Generate Link Token</button>
                    </form>
                  </div>
                  <div class='mt-3'>{$flashHtml}{$tokenHtml}</div>
                  <div class='row g-3 mt-1'>{$cards}</div>
                  <div class='card card-body mt-4'>
                    <div class='fw-semibold mb-2'>Issued tokens</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle mb-0'>
                        <thead>
                          <tr>
                            <th>Token prefix</th>
                            <th>Expires at</th>
                            <th>Status</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$tokenRows}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-4'>
                    <div class='fw-semibold'>How it works</div>
                    <ol class='mb-0 mt-2'>
                      <li>Generate a link token.</li>
                      <li>Open the link token URL in another browser (or share with your alt).</li>
                      <li>Log in with the character you want to link.</li>
                    </ol>
                  </div>";

        return Response::html($renderPage('Linked Characters', $body), 200);
    });

    $registry->route('GET', '/admin/charlink', function () use ($app, $renderPage): Response {
        $flash = $_SESSION['charlink_admin_flash'] ?? null;
        unset($_SESSION['charlink_admin_flash']);

        $links = $app->db->all(
            "SELECT cl.character_id, cl.character_name, cl.user_id, cl.linked_at, eu.character_name AS user_name
             FROM character_links cl
             JOIN eve_users eu ON eu.id = cl.user_id
             WHERE cl.status='linked'
             ORDER BY cl.linked_at DESC
             LIMIT 200"
        );

        $tokens = $app->db->all(
            "SELECT t.id, t.token_prefix, t.expires_at, t.used_at, t.used_character_id, eu.character_name AS user_name
             FROM character_link_tokens t
             JOIN eve_users eu ON eu.id = t.user_id
             ORDER BY t.created_at DESC
             LIMIT 200"
        );

        $flashHtml = '';
        if (is_array($flash)) {
            $type = htmlspecialchars((string)($flash['type'] ?? 'info'));
            $message = htmlspecialchars((string)($flash['message'] ?? ''));
            if ($message !== '') {
                $flashHtml = "<div class='alert alert-{$type}'>{$message}</div>";
            }
        }

        $linkRows = '';
        foreach ($links as $link) {
            $linkId = (int)($link['character_id'] ?? 0);
            $linkName = htmlspecialchars((string)($link['character_name'] ?? ''));
            $userName = htmlspecialchars((string)($link['user_name'] ?? ''));
            $linkedAt = htmlspecialchars((string)($link['linked_at'] ?? ''));
            $linkRows .= "<tr>
                <td>{$linkName}</td>
                <td>{$linkId}</td>
                <td>{$userName}</td>
                <td>{$linkedAt}</td>
                <td>
                  <form method='post' action='/admin/charlink/revoke' onsubmit=\"return confirm('Revoke link for {$linkName}?');\">
                    <input type='hidden' name='character_id' value='{$linkId}'>
                    <button class='btn btn-sm btn-outline-danger'>Revoke</button>
                  </form>
                </td>
              </tr>";
        }
        if ($linkRows === '') {
            $linkRows = "<tr><td colspan='5' class='text-muted'>No active links.</td></tr>";
        }

        $tokenRows = '';
        foreach ($tokens as $token) {
            $tokenId = (int)($token['id'] ?? 0);
            $prefix = htmlspecialchars((string)($token['token_prefix'] ?? ''));
            $userName = htmlspecialchars((string)($token['user_name'] ?? ''));
            $expiresAt = htmlspecialchars((string)($token['expires_at'] ?? ''));
            $usedAt = htmlspecialchars((string)($token['used_at'] ?? ''));
            $usedChar = htmlspecialchars((string)($token['used_character_id'] ?? ''));
            $status = $usedAt !== '' ? "Used ({$usedChar})" : 'Active';
            $tokenRows .= "<tr>
                <td>{$prefix}</td>
                <td>{$userName}</td>
                <td>{$expiresAt}</td>
                <td>{$status}</td>
                <td>
                  <form method='post' action='/admin/charlink/token-revoke' onsubmit=\"return confirm('Delete token {$prefix}?');\">
                    <input type='hidden' name='token_id' value='{$tokenId}'>
                    <button class='btn btn-sm btn-outline-secondary'>Delete</button>
                  </form>
                </td>
              </tr>";
        }
        if ($tokenRows === '') {
            $tokenRows = "<tr><td colspan='5' class='text-muted'>No tokens issued.</td></tr>";
        }

        $body = "<div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                    <div>
                      <h1 class='mb-1'>Character Linker</h1>
                      <div class='text-muted'>Review linked characters and issued link tokens.</div>
                    </div>
                  </div>
                  <div class='mt-3'>{$flashHtml}</div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Active Links</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Character</th>
                            <th>ID</th>
                            <th>Primary</th>
                            <th>Linked at</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$linkRows}</tbody>
                      </table>
                    </div>
                  </div>
                  <div class='card card-body mt-3'>
                    <div class='fw-semibold mb-2'>Link Tokens</div>
                    <div class='table-responsive'>
                      <table class='table table-sm align-middle'>
                        <thead>
                          <tr>
                            <th>Token prefix</th>
                            <th>Primary</th>
                            <th>Expires at</th>
                            <th>Status</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>{$tokenRows}</tbody>
                      </table>
                    </div>
                  </div>";

        return Response::html($renderPage('Character Linker', $body), 200);
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/admin/charlink/revoke', function (Request $req) use ($app): Response {
        $characterId = (int)($req->post['character_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($characterId > 0) {
            $app->db->run(
                "UPDATE character_links
                 SET status='revoked', revoked_at=NOW(), revoked_by_user_id=?
                 WHERE character_id=?",
                [$uid, $characterId]
            );
            $_SESSION['charlink_admin_flash'] = ['type' => 'info', 'message' => 'Link revoked.'];
        }
        return Response::redirect('/admin/charlink');
    }, ['right' => 'charlink.admin']);

    $registry->route('POST', '/admin/charlink/token-revoke', function (Request $req) use ($app): Response {
        $tokenId = (int)($req->post['token_id'] ?? 0);
        if ($tokenId > 0) {
            $app->db->run("DELETE FROM character_link_tokens WHERE id=?", [$tokenId]);
            $_SESSION['charlink_admin_flash'] = ['type' => 'info', 'message' => 'Token deleted.'];
        }
        return Response::redirect('/admin/charlink');
    }, ['right' => 'charlink.admin']);
};
