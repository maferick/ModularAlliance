<?php
declare(strict_types=1);

namespace App\Core\AdminRoutes;

use App\Core\App;
use App\Core\Menu;
use App\Core\ModuleRegistry;
use App\Http\Request;
use App\Http\Response;

final class MenuBuilder
{
    public static function register(App $app, ModuleRegistry $registry, callable $render): void
    {
        $protectedSlugs = ['admin.menu', 'user.login'];

        $registry->route('GET', '/admin/menu', function (): Response {
            return Response::redirect('/admin/menu-builder');
        }, ['right' => 'admin.menu']);

        $registry->route('GET', '/admin/menu-builder', function () use ($app, $render, $protectedSlugs): Response {
            $menuRows = db_all(
                $app->db,
                "SELECT r.slug,
                        r.module_slug AS r_module_slug,
                        r.kind AS r_kind,
                        r.allowed_areas AS r_allowed_areas,
                        r.title AS r_title,
                        r.url AS r_url,
                        r.parent_slug AS r_parent_slug,
                        r.sort_order AS r_sort_order,
                        r.area AS r_area,
                        r.right_slug AS r_right_slug,
                        r.enabled AS r_enabled,
                        o.title AS o_title,
                        o.url AS o_url,
                        o.parent_slug AS o_parent_slug,
                        o.sort_order AS o_sort_order,
                        o.area AS o_area,
                        o.right_slug AS o_right_slug,
                        o.enabled AS o_enabled
                 FROM menu_registry r
                 LEFT JOIN menu_overrides o ON o.slug = r.slug
                 ORDER BY r.slug ASC"
            );

            $overrideRows = db_all(
                $app->db,
                "SELECT o.slug,
                        o.title AS o_title,
                        o.url AS o_url,
                        o.parent_slug AS o_parent_slug,
                        o.sort_order AS o_sort_order,
                        o.area AS o_area,
                        o.right_slug AS o_right_slug,
                        o.enabled AS o_enabled
                 FROM menu_overrides o
                 LEFT JOIN menu_registry r ON r.slug = o.slug
                 WHERE r.slug IS NULL
                 ORDER BY o.slug ASC"
            );

            $rightsRows = db_all(
                $app->db,
                "SELECT slug, description FROM rights ORDER BY description ASC"
            );

            $manifests = $app->modules->getManifests();
            $moduleBySlug = [];
            $moduleLabels = [];
            foreach ($manifests as $manifest) {
                if (!is_array($manifest)) {
                    continue;
                }
                $moduleSlug = (string)($manifest['slug'] ?? '');
                if ($moduleSlug === '') {
                    continue;
                }
                $moduleLabels[$moduleSlug] = (string)($manifest['name'] ?? $moduleSlug);
                foreach (($manifest['menu'] ?? []) as $menuItem) {
                    if (!is_array($menuItem)) {
                        continue;
                    }
                    $slug = (string)($menuItem['slug'] ?? '');
                    if ($slug !== '') {
                        $moduleBySlug[$slug] = $moduleSlug;
                    }
                }
            }

            $items = [];
            foreach ($menuRows as $row) {
                $slug = (string)$row['slug'];
                $items[$slug] = self::buildItem($row, true);
            }

            foreach ($overrideRows as $row) {
                $slug = (string)$row['slug'];
                if (!isset($items[$slug])) {
                    $items[$slug] = self::buildItem($row, false);
                }
            }

            foreach ($items as $slug => &$item) {
                $moduleSlug = $item['module_slug'] ?? ($moduleBySlug[$slug] ?? 'system');
                $moduleLabel = $moduleLabels[$moduleSlug] ?? 'System';
                $item['module'] = $moduleLabel;
            }
            unset($item);

            $moduleGroups = [];
            foreach ($items as $item) {
                $label = $item['module'];
                $moduleGroups[$label][] = $item;
            }
            ksort($moduleGroups, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($moduleGroups as $label => &$groupItems) {
                usort($groupItems, fn($a, $b) => strcasecmp($a['effective']['title'], $b['effective']['title']));
            }
            unset($groupItems);

            $areas = [
                Menu::AREA_LEFT => 'Left',
                Menu::AREA_ADMIN_TOP => 'Admin Top',
                Menu::AREA_TOP_LEFT => 'Top Left',
                Menu::AREA_USER_TOP => 'User Top',
            ];

            $areaTrees = [];
            foreach (array_keys($areas) as $area) {
                $areaTrees[$area] = self::buildAreaTree($items, $area);
            }

            $payload = [
                'items' => $items,
                'rights' => array_map(fn($r) => [
                    'slug' => (string)$r['slug'],
                    'description' => (string)($r['description'] ?? (string)$r['slug']),
                ], $rightsRows),
                'areas' => $areas,
                'protected' => $protectedSlugs,
            ];

            $h = "<h1>Menu Builder</h1>
            <p class='text-muted'>Drag menu items into quadrants, nest them by dropping on another item, and click any item to edit its overrides. Leave fields blank to fall back to module defaults.</p>
            <div class='d-flex flex-wrap gap-2 align-items-center mb-3'>
              <button class='btn btn-success btn-sm' id='menu-save-layout'>Save layout</button>
              <button class='btn btn-outline-warning btn-sm' id='menu-dedupe'>De-duplicate menu items</button>
              <div class='form-check form-switch ms-2'>
                <input class='form-check-input' type='checkbox' id='menu-show-tech'>
                <label class='form-check-label' for='menu-show-tech'>Show technical details</label>
              </div>
              <div class='small text-muted ms-auto' id='menu-status'></div>
            </div>
            <div class='menu-builder'>
              <aside class='menu-builder-sidebar'>
                <div class='small text-muted mb-2'>Menu items by module</div>
                <div class='mb-3'>
                  <label class='form-label small text-muted' for='menu-available-area'>Available items for</label>
                  <select class='form-select form-select-sm' id='menu-available-area'></select>
                </div>";

            $groupIndex = 0;
            foreach ($moduleGroups as $label => $groupItems) {
                $groupIndex++;
                $groupId = 'menu-module-' . $groupIndex;
                $h .= "<div class='menu-module mb-3'>
                  <button class='btn btn-sm btn-outline-secondary w-100 text-start' data-bs-toggle='collapse' data-bs-target='#{$groupId}' aria-expanded='true'>" . htmlspecialchars($label) . "</button>
                  <div class='collapse show' id='{$groupId}'>
                    <ul class='list-unstyled menu-item-list mt-2' data-module='" . htmlspecialchars($label) . "'>";
                foreach ($groupItems as $item) {
                    $slug = $item['slug'];
                    $title = htmlspecialchars($item['effective']['title']);
                    $disabledBadge = ((int)$item['effective']['enabled'] === 1) ? '' : "<span class='badge text-bg-secondary ms-2'>Disabled</span>";
                    $allowedAttr = htmlspecialchars(json_encode($item['allowed_areas'] ?? []));
                    $h .= "<li class='menu-item menu-draggable' draggable='true' data-slug='" . htmlspecialchars($slug) . "' data-source='library' data-allowed-areas='{$allowedAttr}'>
                      <div class='menu-item-card'>
                        <span class='menu-item-title'>{$title}</span>{$disabledBadge}
                      </div>
                      <div class='technical-only small text-muted'>Slug: " . htmlspecialchars($slug) . "</div>
                      <div class='technical-only small text-muted'>Allowed areas: {$allowedAttr}</div>
                    </li>";
                }
                $h .= "</ul></div></div>";
            }

            $h .= "</aside>
              <section class='menu-builder-quadrants'>";

            foreach ($areas as $area => $label) {
                $treeHtml = self::renderTreeHtml($areaTrees[$area], $protectedSlugs);
                $h .= "<div class='menu-quadrant' data-area='" . htmlspecialchars($area) . "'>
                    <div class='menu-quadrant-header'>" . htmlspecialchars($label) . "</div>
                    <ul class='menu-dropzone' data-area='" . htmlspecialchars($area) . "'>{$treeHtml}</ul>
                  </div>";
            }

            $h .= "</section></div>

            <div class='offcanvas offcanvas-end' tabindex='-1' id='menu-editor'>
              <div class='offcanvas-header'>
                <h5 class='offcanvas-title'>Edit menu item</h5>
                <button type='button' class='btn-close text-reset' data-bs-dismiss='offcanvas'></button>
              </div>
              <div class='offcanvas-body'>
                <form id='menu-edit-form'>
                  <input type='hidden' name='slug' id='menu-edit-slug'>
                  <div class='mb-3'>
                    <label class='form-label'>Title</label>
                    <input type='text' class='form-control' name='title' id='menu-edit-title'>
                    <div class='form-text' id='menu-edit-title-default'></div>
                  </div>
                  <div class='mb-3'>
                    <label class='form-label'>URL</label>
                    <input type='text' class='form-control' name='url' id='menu-edit-url'>
                    <div class='form-text' id='menu-edit-url-default'></div>
                  </div>
                  <div class='mb-3'>
                    <label class='form-label'>Enabled</label>
                    <select class='form-select' name='enabled' id='menu-edit-enabled'>
                      <option value=''>Default</option>
                      <option value='1'>Enabled</option>
                      <option value='0'>Disabled</option>
                    </select>
                    <div class='form-text' id='menu-edit-enabled-default'></div>
                  </div>
                  <div class='mb-3'>
                    <label class='form-label'>Parent</label>
                    <select class='form-select' name='parent_slug' id='menu-edit-parent'>
                      <option value=''>No parent (default)</option>
                    </select>
                    <div class='form-text' id='menu-edit-parent-default'></div>
                  </div>
                  <div class='mb-3'>
                    <label class='form-label'>Sort</label>
                    <input type='number' class='form-control' name='sort_order' id='menu-edit-sort'>
                    <div class='form-text' id='menu-edit-sort-default'></div>
                  </div>
                  <div class='mb-3'>
                    <label class='form-label'>Right (permission)</label>
                    <select class='form-select' name='right_slug' id='menu-edit-right'>
                      <option value=''>Default</option>
                    </select>
                    <div class='form-text' id='menu-edit-right-default'></div>
                  </div>
                  <div class='technical-only small text-muted mb-3' id='menu-edit-slug-display'></div>
                  <div class='d-flex gap-2'>
                    <button class='btn btn-primary' type='submit'>Save overrides</button>
                    <button class='btn btn-outline-secondary' type='button' data-bs-dismiss='offcanvas'>Close</button>
                  </div>
                </form>
              </div>
            </div>

            <style>
              .menu-builder { display: grid; grid-template-columns: 280px 1fr; gap: 1.5rem; }
              .menu-builder-sidebar { max-height: 75vh; overflow-y: auto; padding-right: 0.5rem; }
              .menu-builder-quadrants { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
              .menu-quadrant { border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 0.75rem; background: rgba(0,0,0,0.15); min-height: 240px; }
              .menu-quadrant-header { font-weight: 600; margin-bottom: 0.5rem; }
              .menu-dropzone { list-style: none; padding-left: 0; min-height: 200px; }
              .menu-item { margin-bottom: 0.4rem; }
              .menu-item-card { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.35rem 0.6rem; background: rgba(255,255,255,0.05); border-radius: 6px; border: 1px solid rgba(255,255,255,0.12); cursor: pointer; }
              .menu-item-card:hover { background: rgba(255,255,255,0.1); }
              .menu-item-title { font-weight: 500; }
              .menu-item-remove { flex-shrink: 0; }
              .menu-item[data-source='library'] .menu-item-remove { display: none; }
              .menu-children { list-style: none; padding-left: 1rem; margin-top: 0.4rem; }
              .menu-draggable.dragging { opacity: 0.5; }
              .menu-dropzone.drag-over { outline: 2px dashed rgba(255,255,255,0.4); outline-offset: 4px; }
              .show-technical .technical-only { display: block !important; }
              .technical-only { display: none; }
              @media (max-width: 992px) {
                .menu-builder { grid-template-columns: 1fr; }
                .menu-builder-sidebar { max-height: none; }
                .menu-builder-quadrants { grid-template-columns: 1fr; }
              }
            </style>

            <script>
              (function() {
                const menuData = " . json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ";
                const showTechToggle = document.getElementById('menu-show-tech');
                const statusEl = document.getElementById('menu-status');
                const saveLayoutBtn = document.getElementById('menu-save-layout');
                const dedupeBtn = document.getElementById('menu-dedupe');
                const availableAreaSelect = document.getElementById('menu-available-area');
                const editor = document.getElementById('menu-editor');
                const editForm = document.getElementById('menu-edit-form');
                const editSlug = document.getElementById('menu-edit-slug');
                const editTitle = document.getElementById('menu-edit-title');
                const editUrl = document.getElementById('menu-edit-url');
                const editEnabled = document.getElementById('menu-edit-enabled');
                const editParent = document.getElementById('menu-edit-parent');
                const editSort = document.getElementById('menu-edit-sort');
                const editRight = document.getElementById('menu-edit-right');
                const editSlugDisplay = document.getElementById('menu-edit-slug-display');

                const defaultFields = {
                  title: document.getElementById('menu-edit-title-default'),
                  url: document.getElementById('menu-edit-url-default'),
                  enabled: document.getElementById('menu-edit-enabled-default'),
                  parent_slug: document.getElementById('menu-edit-parent-default'),
                  sort_order: document.getElementById('menu-edit-sort-default'),
                  right_slug: document.getElementById('menu-edit-right-default'),
                };

                const items = menuData.items || {};
                const rights = menuData.rights || [];
                const protectedSlugs = menuData.protected || [];
                const areaLabels = menuData.areas || {};
                const rightsMap = {};
                rights.forEach(right => {
                  rightsMap[right.slug] = right.description || right.slug;
                });

                function isProtectedSlug(slug) {
                  return protectedSlugs.includes(slug);
                }

                function showStatus(message, isError) {
                  statusEl.textContent = message;
                  statusEl.className = isError ? 'text-danger small' : 'text-success small';
                  if (message) {
                    setTimeout(() => {
                      statusEl.textContent = '';
                      statusEl.className = 'small text-muted';
                    }, 3000);
                  }
                }

                function titleForItem(item) {
                  return (item && item.effective && item.effective.title) ? item.effective.title : 'Untitled menu item';
                }

                function rightLabelFor(slug, showTechnical) {
                  if (!slug) return '—';
                  const label = rightsMap[slug] || slug;
                  return showTechnical ? (label + ' (' + slug + ')') : label;
                }

                function areaLabelFor(area) {
                  return areaLabels[area] || area;
                }

                function allowedAreasFor(slug) {
                  const item = items[slug];
                  if (!item) return [];
                  if (Array.isArray(item.allowed_areas) && item.allowed_areas.length) {
                    return item.allowed_areas;
                  }
                  if (item.effective && item.effective.area) {
                    return [item.effective.area];
                  }
                  return [];
                }

                function isAreaAllowed(slug, area) {
                  const allowed = allowedAreasFor(slug);
                  return allowed.includes(area);
                }

                function parentLabelFor(slug, showTechnical) {
                  if (!slug) return '—';
                  const item = items[slug];
                  const label = item ? titleForItem(item) : slug;
                  return showTechnical ? (label + ' (' + slug + ')') : label;
                }

                function buildParentOptions(showTechnical) {
                  const entries = Object.values(items).map(item => ({
                    slug: item.slug,
                    label: titleForItem(item),
                  }));
                  entries.sort((a, b) => a.label.localeCompare(b.label));

                  editParent.innerHTML = `<option value=''>No parent (default)</option>`;
                  entries.forEach(entry => {
                    const option = document.createElement('option');
                    option.value = entry.slug;
                    option.textContent = showTechnical ? (entry.label + ' (' + entry.slug + ')') : entry.label;
                    editParent.appendChild(option);
                  });
                }

                function buildRightOptions(showTechnical) {
                  editRight.innerHTML = `<option value=''>Default</option>`;
                  rights.forEach(right => {
                    const option = document.createElement('option');
                    const label = right.description || right.slug;
                    option.value = right.slug;
                    option.textContent = showTechnical ? (label + ' (' + right.slug + ')') : label;
                    editRight.appendChild(option);
                  });
                }

                let draggingSlug = null;
                let draggingSource = null;
                let draggingElement = null;

                function setDragState(el, dragging) {
                  if (!el) return;
                  if (dragging) {
                    el.classList.add('dragging');
                  } else {
                    el.classList.remove('dragging');
                  }
                }

                function createMenuItemElement(slug) {
                  const item = items[slug];
                  if (!item) return null;
                  const li = document.createElement('li');
                  li.className = 'menu-item menu-draggable';
                  li.draggable = true;
                  li.dataset.slug = slug;
                  li.dataset.allowedAreas = JSON.stringify(allowedAreasFor(slug));

                  const card = document.createElement('div');
                  card.className = 'menu-item-card';
                  const title = document.createElement('span');
                  title.className = 'menu-item-title';
                  title.textContent = titleForItem(item);
                  card.appendChild(title);

                  if (parseInt(item.effective.enabled, 10) !== 1) {
                    const badge = document.createElement('span');
                    badge.className = 'badge text-bg-secondary ms-2';
                    badge.textContent = 'Disabled';
                    card.appendChild(badge);
                  }

                  const removeBtn = document.createElement('button');
                  removeBtn.type = 'button';
                  removeBtn.className = 'btn btn-sm btn-outline-danger menu-item-remove';
                  removeBtn.textContent = 'Remove';
                  removeBtn.disabled = isProtectedSlug(slug);
                  removeBtn.title = isProtectedSlug(slug) ? 'Menu Builder and Login must stay in a quadrant.' : 'Remove from layout';
                  card.appendChild(removeBtn);

                  const technical = document.createElement('div');
                  technical.className = 'technical-only small text-muted';
                  technical.textContent = 'Slug: ' + slug;

                  const allowedInfo = document.createElement('div');
                  allowedInfo.className = 'technical-only small text-muted';
                  allowedInfo.textContent = 'Allowed areas: ' + JSON.stringify(allowedAreasFor(slug));

                  const children = document.createElement('ul');
                  children.className = 'menu-children';

                  li.appendChild(card);
                  li.appendChild(technical);
                  li.appendChild(allowedInfo);
                  li.appendChild(children);
                  return li;
                }

                function closestMenuItem(el) {
                  return el ? el.closest('.menu-item') : null;
                }

                function updateLayoutFromDom() {
                  const dropzones = document.querySelectorAll('.menu-dropzone');
                  dropzones.forEach(zone => {
                    const area = zone.dataset.area;
                    updateList(zone, area, null);
                  });
                }

                function buildEditPayload(slug, overrideData, enabledValue) {
                  const overrides = overrideData || {};
                  return {
                    action: 'edit',
                    slug,
                    title: overrides.title ?? '',
                    url: overrides.url ?? '',
                    parent_slug: overrides.parent_slug ?? '',
                    sort_order: overrides.sort_order ?? '',
                    right_slug: overrides.right_slug ?? '',
                    enabled: enabledValue ?? (overrides.enabled ?? ''),
                  };
                }

                function applyItemUpdate(itemData) {
                  if (!itemData || !items[itemData.slug]) return;
                  items[itemData.slug].overrides = itemData.overrides;
                  items[itemData.slug].effective = itemData.effective;
                  const slug = itemData.slug;
                  const titleNodes = document.querySelectorAll('.menu-item[data-slug=\"' + slug + '\"] .menu-item-title');
                  titleNodes.forEach(node => { node.textContent = itemData.effective.title || 'Untitled menu item'; });

                  const cards = document.querySelectorAll('.menu-item[data-slug=\"' + slug + '\"] .menu-item-card');
                  cards.forEach(card => {
                    const existingBadge = card.querySelector('.badge.text-bg-secondary');
                    if (parseInt(itemData.effective.enabled, 10) === 1) {
                      if (existingBadge) existingBadge.remove();
                    } else if (!existingBadge) {
                      const badge = document.createElement('span');
                      badge.className = 'badge text-bg-secondary ms-2';
                      badge.textContent = 'Disabled';
                      card.appendChild(badge);
                    }
                  });
                }

                function updateList(listEl, area, parentSlug) {
                  const children = Array.from(listEl.children).filter(el => el.classList.contains('menu-item'));
                  children.forEach((child, index) => {
                    const slug = child.dataset.slug;
                    if (!items[slug]) return;
                    items[slug].layout = {
                      area,
                      parent_slug: parentSlug,
                      sort_order: (index + 1) * 10,
                    };
                    const childList = child.querySelector('.menu-children');
                    if (childList) {
                      updateList(childList, area, slug);
                    }
                  });
                }

                function syncLayoutOverrides(overrides) {
                  Object.entries(overrides).forEach(([slug, data]) => {
                    if (!items[slug]) return;
                    items[slug].overrides.area = data.area;
                    items[slug].overrides.parent_slug = data.parent_slug;
                    items[slug].overrides.sort_order = data.sort_order;
                    items[slug].effective.area = data.area || items[slug].defaults.area || items[slug].effective.area;
                    items[slug].effective.parent_slug = data.parent_slug ?? items[slug].defaults.parent_slug;
                    items[slug].effective.sort_order = data.sort_order ?? items[slug].defaults.sort_order;
                  });
                }

                function normalizeDropPosition(target, event) {
                  const rect = target.getBoundingClientRect();
                  const offset = event.clientY - rect.top;
                  const ratio = offset / rect.height;
                  if (ratio < 0.3) return 'before';
                  if (ratio > 0.7) return 'after';
                  return 'inside';
                }

                function attachDragHandlers(container) {
                  container.addEventListener('dragstart', event => {
                    const item = closestMenuItem(event.target);
                    if (!item) return;
                    const slug = item.dataset.slug;
                    const source = item.dataset.source || 'canvas';
                    event.dataTransfer.setData('text/plain', slug);
                    event.dataTransfer.setData('text/source', source);
                    event.dataTransfer.effectAllowed = 'move';
                    setDragState(item, true);
                    draggingSlug = slug;
                    draggingSource = source;
                    draggingElement = item;
                  });

                  container.addEventListener('dragend', event => {
                    const item = closestMenuItem(event.target);
                    if (!item) return;
                    setDragState(item, false);
                    draggingSlug = null;
                    draggingSource = null;
                    draggingElement = null;
                  });

                  container.addEventListener('dragover', event => {
                    const item = closestMenuItem(event.target);
                    const zone = event.target.closest('.menu-dropzone');
                    const inCanvas = !!event.target.closest('.menu-builder-quadrants');
                    if ((!item && !zone) || !inCanvas) return;
                    event.preventDefault();
                  });

                  container.addEventListener('drop', event => {
                    const slug = event.dataTransfer.getData('text/plain') || draggingSlug;
                    if (!slug) return;
                    let dragged = draggingSource === 'library'
                      ? document.querySelector('.menu-builder-quadrants .menu-item[data-slug=\"' + slug + '\"]')
                      : draggingElement;
                    if (!dragged && draggingSource === 'library') {
                      dragged = createMenuItemElement(slug);
                    }
                    if (!dragged) return;

                    const targetItem = closestMenuItem(event.target);
                    const targetZone = event.target.closest('.menu-dropzone');
                    const inCanvas = !!event.target.closest('.menu-builder-quadrants');
                    if (!inCanvas) {
                      return;
                    }

                    event.preventDefault();

                    const dropZone = targetZone || (targetItem ? targetItem.closest('.menu-dropzone') : null);
                    const area = dropZone ? dropZone.dataset.area : null;
                    if (area && !isAreaAllowed(slug, area)) {
                      const title = titleForItem(items[slug]);
                      showStatus(`\${title} can only be placed in \${areaLabelFor(area)}.`, true);
                      return;
                    }

                    if (targetItem && targetItem !== dragged) {
                      const position = normalizeDropPosition(targetItem, event);
                      if (position === 'inside') {
                        let childList = targetItem.querySelector('.menu-children');
                        if (!childList) {
                          childList = document.createElement('ul');
                          childList.className = 'menu-children';
                          targetItem.appendChild(childList);
                        }
                        childList.appendChild(dragged);
                      } else if (position === 'before') {
                        targetItem.parentElement.insertBefore(dragged, targetItem);
                      } else {
                        targetItem.parentElement.insertBefore(dragged, targetItem.nextSibling);
                      }
                    } else if (targetZone) {
                      targetZone.appendChild(dragged);
                    }

                    updateLayoutFromDom();
                  });
                }

                function setupEditDrawer() {
                  document.addEventListener('click', event => {
                    const card = event.target.closest('.menu-item-card');
                    if (!card) return;
                    const removeButton = event.target.closest('.menu-item-remove');
                    if (removeButton) return;
                    const itemEl = card.closest('.menu-item');
                    if (!itemEl) return;
                    const slug = itemEl.dataset.slug;
                    const item = items[slug];
                    if (!item) return;

                    editSlug.value = slug;
                    editTitle.value = item.overrides.title ?? '';
                    editUrl.value = item.overrides.url ?? '';
                    editEnabled.value = item.overrides.enabled === null || item.overrides.enabled === undefined ? '' : String(item.overrides.enabled);
                    editParent.value = item.overrides.parent_slug ?? '';
                    editSort.value = item.overrides.sort_order ?? '';
                    editRight.value = item.overrides.right_slug ?? '';

                    defaultFields.title.textContent = 'Default: ' + (item.defaults.title || '—');
                    defaultFields.url.textContent = 'Default: ' + (item.defaults.url || '—');
                    defaultFields.enabled.textContent = 'Default: ' + (item.defaults.enabled === null ? '—' : (item.defaults.enabled === 1 ? 'Enabled' : 'Disabled'));
                    defaultFields.parent_slug.textContent = 'Default: ' + parentLabelFor(item.defaults.parent_slug, showTechToggle.checked);
                    defaultFields.sort_order.textContent = 'Default: ' + (item.defaults.sort_order ?? '—');

                    defaultFields.right_slug.textContent = 'Default: ' + rightLabelFor(item.defaults.right_slug, showTechToggle.checked);
                    editSlugDisplay.textContent = 'Slug: ' + slug;

                    const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(editor);
                    offcanvas.show();
                  });
                }

                async function postJson(payload) {
                  const response = await fetch('/admin/menu-builder/save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                  });
                  const text = await response.text();
                  let data = null;
                  try {
                    data = JSON.parse(text);
                  } catch (err) {
                    throw new Error(text || 'Unexpected response');
                  }
                  if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Save failed');
                  }
                  return data;
                }

                saveLayoutBtn.addEventListener('click', async () => {
                  updateLayoutFromDom();
                  const payloadItems = {};
                  Object.values(items).forEach(item => {
                    if (!item.layout) return;
                    payloadItems[item.slug] = item.layout;
                  });
                  try {
                    const data = await postJson({ action: 'layout', items: payloadItems });
                    if (data.overrides) {
                      syncLayoutOverrides(data.overrides);
                    }
                    showStatus(data.message || 'Layout saved.');
                  } catch (err) {
                    showStatus(err.message, true);
                  }
                });

                editForm.addEventListener('submit', async (event) => {
                  event.preventDefault();
                  if (isProtectedSlug(editSlug.value) && editEnabled.value === '0') {
                    showStatus('Menu Builder and Login must stay in a quadrant.', true);
                    return;
                  }
                  const payload = {
                    action: 'edit',
                    slug: editSlug.value,
                    title: editTitle.value,
                    url: editUrl.value,
                    enabled: editEnabled.value,
                    parent_slug: editParent.value,
                    sort_order: editSort.value,
                    right_slug: editRight.value,
                  };
                  try {
                    const data = await postJson(payload);
                    applyItemUpdate(data.item);
                    showStatus(data.message || 'Overrides saved.');
                  } catch (err) {
                    showStatus(err.message, true);
                  }
                });

                document.addEventListener('click', async (event) => {
                  const removeBtn = event.target.closest('.menu-item-remove');
                  if (!removeBtn) return;
                  event.stopPropagation();
                  const itemEl = removeBtn.closest('.menu-item');
                  if (!itemEl) return;
                  const slug = itemEl.dataset.slug;
                  if (isProtectedSlug(slug)) {
                    showStatus('Menu Builder and Login must stay in a quadrant.', true);
                    return;
                  }
                  const item = items[slug];
                  if (!item) return;
                  itemEl.remove();
                  delete items[slug].layout;
                  updateLayoutFromDom();
                  try {
                    const payload = buildEditPayload(slug, item.overrides, '0');
                    const data = await postJson(payload);
                    applyItemUpdate(data.item);
                    showStatus(data.message || 'Menu item removed.');
                  } catch (err) {
                    showStatus(err.message, true);
                  }
                });

                showTechToggle.addEventListener('change', () => {
                  document.body.classList.toggle('show-technical', showTechToggle.checked);
                  buildParentOptions(showTechToggle.checked);
                  buildRightOptions(showTechToggle.checked);
                });

                function populateAvailableAreaOptions() {
                  const areas = Object.keys(areaLabels);
                  availableAreaSelect.innerHTML = '';
                  areas.forEach(area => {
                    const option = document.createElement('option');
                    option.value = area;
                    option.textContent = areaLabelFor(area);
                    availableAreaSelect.appendChild(option);
                  });
                  if (areas.length) {
                    availableAreaSelect.value = areas[0];
                  }
                }

                function filterAvailableItems() {
                  const area = availableAreaSelect.value;
                  document.querySelectorAll('.menu-builder-sidebar .menu-item[data-source=\"library\"]').forEach(itemEl => {
                    const slug = itemEl.dataset.slug;
                    const allowed = allowedAreasFor(slug);
                    const show = allowed.includes(area);
                    itemEl.style.display = show ? '' : 'none';
                  });

                  document.querySelectorAll('.menu-module').forEach(moduleEl => {
                    const visibleItem = moduleEl.querySelector('.menu-item[data-source=\"library\"]:not([style*=\"display: none\"])');
                    moduleEl.style.display = visibleItem ? '' : 'none';
                  });
                }

                availableAreaSelect.addEventListener('change', filterAvailableItems);

                dedupeBtn.addEventListener('click', async () => {
                  if (!confirm('De-duplicate menu items with identical URLs?')) {
                    return;
                  }
                  try {
                    const data = await postJson({ action: 'dedupe' });
                    showStatus(data.message || 'Menu items de-duplicated.');
                    if (data.refresh) {
                      setTimeout(() => location.reload(), 800);
                    }
                  } catch (err) {
                    showStatus(err.message, true);
                  }
                });

                populateAvailableAreaOptions();
                buildParentOptions(false);
                buildRightOptions(false);
                attachDragHandlers(document);
                setupEditDrawer();
                updateLayoutFromDom();
                filterAvailableItems();
              })();
            </script>";

            return $render('Menu Builder', $h);
        }, ['right' => 'admin.menu']);

        $registry->route('POST', '/admin/menu-builder/save', function (Request $req) use ($app, $protectedSlugs): Response {
            $raw = file_get_contents('php://input');
            $data = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            if ($data === []) {
                $data = $req->post;
            }

            $action = (string)($data['action'] ?? '');
            if ($action === '') {
                return self::jsonResponse(['ok' => false, 'message' => 'Missing action.'], 400);
            }

            $registryRows = db_all(
                $app->db,
                "SELECT slug, title, url, parent_slug, sort_order, area, right_slug, enabled, allowed_areas
                 FROM menu_registry"
            );
            $registryMap = [];
            foreach ($registryRows as $row) {
                $registryMap[(string)$row['slug']] = $row;
            }

            $allowedAreas = [Menu::AREA_LEFT, Menu::AREA_ADMIN_TOP, Menu::AREA_USER_TOP, Menu::AREA_TOP_LEFT];

            if ($action === 'dedupe') {
                $rows = db_all(
                    $app->db,
                    "SELECT id, slug, url
                     FROM menu_registry
                     WHERE url <> ''
                     ORDER BY id ASC"
                );
                if (empty($rows)) {
                    return self::jsonResponse(['ok' => true, 'message' => 'No menu items to de-duplicate.', 'refresh' => false]);
                }

                $overrideRows = db_all(
                    $app->db,
                    "SELECT slug, title, url, parent_slug, sort_order, area, right_slug, enabled
                     FROM menu_overrides"
                );
                $overrideMap = [];
                foreach ($overrideRows as $row) {
                    $overrideMap[(string)$row['slug']] = $row;
                }

                $byUrl = [];
                foreach ($rows as $row) {
                    $url = strtolower(trim((string)$row['url']));
                    if ($url === '') {
                        continue;
                    }
                    $byUrl[$url][] = $row;
                }

                $removed = 0;
                $merged = 0;

                db_tx($app->db, function () use (&$byUrl, &$overrideMap, &$removed, &$merged, $app): void {
                    $fields = ['title', 'url', 'parent_slug', 'sort_order', 'area', 'right_slug', 'enabled'];

                    foreach ($byUrl as $group) {
                        if (count($group) <= 1) {
                            continue;
                        }
                        $canonical = $group[0];
                        $canonicalSlug = (string)$canonical['slug'];

                        foreach (array_slice($group, 1) as $dup) {
                            $dupSlug = (string)$dup['slug'];

                            db_exec($app->db, "UPDATE menu_registry SET parent_slug=? WHERE parent_slug=?", [$canonicalSlug, $dupSlug]);
                            db_exec($app->db, "UPDATE menu_overrides SET parent_slug=? WHERE parent_slug=?", [$canonicalSlug, $dupSlug]);

                            $dupOverride = $overrideMap[$dupSlug] ?? null;
                            if ($dupOverride) {
                                $canonicalOverride = $overrideMap[$canonicalSlug] ?? array_fill_keys($fields, null);
                                foreach ($fields as $field) {
                                    if (($canonicalOverride[$field] ?? null) === null && $dupOverride[$field] !== null) {
                                        $canonicalOverride[$field] = $dupOverride[$field];
                                    }
                                }

                                db_exec(
                                    $app->db,
                                    "INSERT INTO menu_overrides (slug, title, url, parent_slug, sort_order, area, right_slug, enabled)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE
                                       title=VALUES(title),
                                       url=VALUES(url),
                                       parent_slug=VALUES(parent_slug),
                                       sort_order=VALUES(sort_order),
                                       area=VALUES(area),
                                       right_slug=VALUES(right_slug),
                                       enabled=VALUES(enabled)",
                                    [
                                        $canonicalSlug,
                                        $canonicalOverride['title'],
                                        $canonicalOverride['url'],
                                        $canonicalOverride['parent_slug'],
                                        $canonicalOverride['sort_order'],
                                        $canonicalOverride['area'],
                                        $canonicalOverride['right_slug'],
                                        $canonicalOverride['enabled'],
                                    ]
                                );

                                $overrideMap[$canonicalSlug] = $canonicalOverride;
                                $merged++;
                            }

                            db_exec($app->db, "DELETE FROM menu_overrides WHERE slug=?", [$dupSlug]);
                            db_exec($app->db, "DELETE FROM menu_registry WHERE slug=?", [$dupSlug]);
                            $removed++;
                        }
                    }
                });

                $message = $removed > 0
                    ? "Removed {$removed} duplicate menu items and merged {$merged} overrides."
                    : 'No duplicate menu items found.';

                return self::jsonResponse(['ok' => true, 'message' => $message, 'refresh' => $removed > 0]);
            }

            if ($action === 'layout') {
                $items = $data['items'] ?? [];
                if (!is_array($items)) {
                    return self::jsonResponse(['ok' => false, 'message' => 'Invalid layout payload.'], 400);
                }

                $overrides = [];

                foreach ($items as $slug => $layout) {
                    if (!is_string($slug) || $slug === '' || !is_array($layout)) {
                        continue;
                    }
                    if (!isset($registryMap[$slug])) {
                        return self::jsonResponse(['ok' => false, 'message' => "Unknown menu item {$slug}."], 400);
                    }
                    $areaRaw = (string)($layout['area'] ?? '');
                    $area = Menu::normalizeArea($areaRaw);
                    if (!in_array($area, $allowedAreas, true)) {
                        return self::jsonResponse(['ok' => false, 'message' => "Invalid area for {$slug}."] , 400);
                    }

                    $allowedAreasRaw = $registryMap[$slug]['allowed_areas'] ?? null;
                    $allowedAreasList = [];
                    if (is_string($allowedAreasRaw) && $allowedAreasRaw !== '') {
                        $decoded = json_decode($allowedAreasRaw, true);
                        if (is_array($decoded)) {
                            $allowedAreasList = array_values(array_filter($decoded, 'is_string'));
                        }
                    }
                    if (empty($allowedAreasList)) {
                        $allowedAreasList = [$area];
                    }
                    if (!in_array($area, $allowedAreasList, true)) {
                        return self::jsonResponse(['ok' => false, 'message' => "Menu item {$slug} cannot be placed in {$area}."], 400);
                    }

                    $parent = trim((string)($layout['parent_slug'] ?? ''));
                    $parent = $parent === '' ? null : $parent;
                    if ($parent === $slug) {
                        $parent = null;
                    }

                    $sort = $layout['sort_order'] ?? null;
                    $sortVal = null;
                    if (is_numeric($sort)) {
                        $sortVal = (int)$sort;
                    }

                    $defaults = $registryMap[$slug] ?? [];
                    $defaultArea = isset($defaults['area']) ? Menu::normalizeArea((string)$defaults['area']) : null;
                    $defaultParent = isset($defaults['parent_slug']) ? (string)$defaults['parent_slug'] : null;
                    $defaultSort = isset($defaults['sort_order']) ? (int)$defaults['sort_order'] : null;

                    $areaOverride = ($defaultArea !== null && $area === $defaultArea) ? null : $area;
                    $parentOverride = ($defaultParent !== null && $parent === $defaultParent) ? null : $parent;
                    $sortOverride = ($defaultSort !== null && $sortVal === $defaultSort) ? null : $sortVal;

                    db_exec(
                        $app->db,
                        "INSERT INTO menu_overrides (slug, area, parent_slug, sort_order)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                           area=VALUES(area),
                           parent_slug=VALUES(parent_slug),
                           sort_order=VALUES(sort_order)",
                        [$slug, $areaOverride, $parentOverride, $sortOverride]
                    );

                    $overrides[$slug] = [
                        'area' => $areaOverride,
                        'parent_slug' => $parentOverride,
                        'sort_order' => $sortOverride,
                    ];
                }

                return self::jsonResponse([
                    'ok' => true,
                    'message' => 'Layout saved.',
                    'overrides' => $overrides,
                ]);
            }

            if ($action === 'edit') {
                $slug = trim((string)($data['slug'] ?? ''));
                if ($slug === '') {
                    return self::jsonResponse(['ok' => false, 'message' => 'Missing menu slug.'], 400);
                }

                $title = trim((string)($data['title'] ?? ''));
                $url = trim((string)($data['url'] ?? ''));
                $parent = trim((string)($data['parent_slug'] ?? ''));
                $sortRaw = trim((string)($data['sort_order'] ?? ''));
                $right = trim((string)($data['right_slug'] ?? ''));
                $enabledRaw = trim((string)($data['enabled'] ?? ''));

                $title = $title === '' ? null : $title;
                $url = $url === '' ? null : $url;
                $parent = $parent === '' ? null : $parent;
                $right = $right === '' ? null : $right;

                $sort = null;
                if ($sortRaw !== '' && is_numeric($sortRaw)) {
                    $sort = (int)$sortRaw;
                }

                $enabled = null;
                if ($enabledRaw === '0' || $enabledRaw === '1') {
                    $enabled = (int)$enabledRaw;
                }

                if (in_array($slug, $protectedSlugs, true) && $enabled === 0) {
                    return self::jsonResponse(['ok' => false, 'message' => 'Menu Builder and Login must stay enabled.'], 400);
                }

                db_exec(
                    $app->db,
                    "INSERT INTO menu_overrides (slug, title, url, parent_slug, sort_order, right_slug, enabled)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       title=VALUES(title),
                       url=VALUES(url),
                       parent_slug=VALUES(parent_slug),
                       sort_order=VALUES(sort_order),
                       right_slug=VALUES(right_slug),
                       enabled=VALUES(enabled)",
                    [$slug, $title, $url, $parent, $sort, $right, $enabled]
                );

                $defaults = $registryMap[$slug] ?? [];
                $effective = [
                    'title' => $title ?? ($defaults['title'] ?? 'Untitled menu item'),
                    'url' => $url ?? ($defaults['url'] ?? ''),
                    'parent_slug' => $parent ?? ($defaults['parent_slug'] ?? null),
                    'sort_order' => $sort ?? ($defaults['sort_order'] ?? null),
                    'right_slug' => $right ?? ($defaults['right_slug'] ?? null),
                    'enabled' => $enabled ?? ($defaults['enabled'] ?? 1),
                    'area' => Menu::normalizeArea((string)($defaults['area'] ?? Menu::AREA_LEFT)),
                ];

                return self::jsonResponse([
                    'ok' => true,
                    'message' => 'Overrides saved.',
                    'item' => [
                        'slug' => $slug,
                        'overrides' => [
                            'title' => $title,
                            'url' => $url,
                            'parent_slug' => $parent,
                            'sort_order' => $sort,
                            'right_slug' => $right,
                            'enabled' => $enabled,
                            'area' => Menu::normalizeArea((string)($defaults['area'] ?? Menu::AREA_LEFT)),
                        ],
                        'effective' => $effective,
                    ],
                ]);
            }

            return self::jsonResponse(['ok' => false, 'message' => 'Unsupported action.'], 400);
        }, ['right' => 'admin.menu']);
    }

    private static function buildItem(array $row, bool $hasRegistry): array
    {
        $slug = (string)$row['slug'];
        $allowedAreas = [];
        if ($hasRegistry && isset($row['r_allowed_areas'])) {
            $decoded = json_decode((string)$row['r_allowed_areas'], true);
            if (is_array($decoded)) {
                $allowedAreas = array_values(array_filter($decoded, 'is_string'));
            }
        }
        $defaults = [
            'module_slug' => $hasRegistry ? (string)($row['r_module_slug'] ?? 'system') : null,
            'kind' => $hasRegistry ? (string)($row['r_kind'] ?? 'action') : null,
            'allowed_areas' => $hasRegistry ? $allowedAreas : null,
            'title' => $hasRegistry ? (string)($row['r_title'] ?? '') : null,
            'url' => $hasRegistry ? (string)($row['r_url'] ?? '') : null,
            'parent_slug' => $hasRegistry ? ($row['r_parent_slug'] ?? null) : null,
            'sort_order' => $hasRegistry ? (int)($row['r_sort_order'] ?? 10) : null,
            'area' => $hasRegistry ? Menu::normalizeArea((string)($row['r_area'] ?? Menu::AREA_LEFT)) : null,
            'right_slug' => $hasRegistry ? ($row['r_right_slug'] ?? null) : null,
            'enabled' => $hasRegistry ? (int)($row['r_enabled'] ?? 1) : null,
        ];

        $overrides = [
            'title' => $row['o_title'] ?? null,
            'url' => $row['o_url'] ?? null,
            'parent_slug' => $row['o_parent_slug'] ?? null,
            'sort_order' => $row['o_sort_order'] ?? null,
            'area' => isset($row['o_area']) ? Menu::normalizeArea((string)$row['o_area']) : null,
            'right_slug' => $row['o_right_slug'] ?? null,
            'enabled' => $row['o_enabled'] ?? null,
        ];

        $effective = [
            'title' => $overrides['title'] ?? $defaults['title'] ?? 'Untitled menu item',
            'url' => $overrides['url'] ?? $defaults['url'] ?? '',
            'parent_slug' => $overrides['parent_slug'] ?? $defaults['parent_slug'],
            'sort_order' => $overrides['sort_order'] ?? $defaults['sort_order'] ?? 10,
            'area' => $overrides['area'] ?? $defaults['area'] ?? Menu::AREA_LEFT,
            'right_slug' => $overrides['right_slug'] ?? $defaults['right_slug'],
            'enabled' => $overrides['enabled'] ?? $defaults['enabled'] ?? 1,
        ];

        return [
            'slug' => $slug,
            'module_slug' => $defaults['module_slug'] ?? 'system',
            'kind' => $defaults['kind'] ?? 'action',
            'allowed_areas' => $defaults['allowed_areas'] ?? [$effective['area']],
            'defaults' => $defaults,
            'overrides' => $overrides,
            'effective' => $effective,
            'module' => 'System',
        ];
    }

    private static function buildAreaTree(array $items, string $area): array
    {
        $area = Menu::normalizeArea($area);
        $nodes = [];
        foreach ($items as $item) {
            if ($item['effective']['area'] !== $area) {
                continue;
            }
            if ((int)$item['effective']['enabled'] !== 1) {
                continue;
            }
            $nodes[$item['slug']] = [
                'slug' => $item['slug'],
                'title' => $item['effective']['title'],
                'enabled' => $item['effective']['enabled'],
                'children' => [],
                'sort_order' => (int)$item['effective']['sort_order'],
                'parent_slug' => $item['effective']['parent_slug'],
            ];
        }

        $root = [];
        foreach ($nodes as $slug => &$node) {
            $parent = $node['parent_slug'];
            if ($parent && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = &$node;
            } else {
                $root[] = &$node;
            }
        }
        unset($node);

        $sortFn = function (&$arr) use (&$sortFn): void {
            usort($arr, fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcasecmp($a['title'], $b['title']));
            foreach ($arr as &$child) {
                if (!empty($child['children'])) {
                    $sortFn($child['children']);
                }
            }
        };
        $sortFn($root);

        return $root;
    }

    private static function renderTreeHtml(array $nodes, array $protectedSlugs): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $title = htmlspecialchars((string)$node['title']);
            $slug = htmlspecialchars((string)$node['slug']);
            $disabledBadge = ((int)$node['enabled'] === 1) ? '' : "<span class='badge text-bg-secondary ms-2'>Disabled</span>";
            $removeDisabled = in_array((string)$node['slug'], $protectedSlugs, true) ? 'disabled' : '';
            $removeTitle = in_array((string)$node['slug'], $protectedSlugs, true)
                ? 'Menu Builder and Login must stay in a quadrant.'
                : 'Remove from layout';
            $children = self::renderTreeHtml($node['children'] ?? [], $protectedSlugs);
            $childrenHtml = $children !== '' ? "<ul class='menu-children'>{$children}</ul>" : "<ul class='menu-children'></ul>";

            $html .= "<li class='menu-item menu-draggable' draggable='true' data-slug='{$slug}'>
              <div class='menu-item-card'>
                <span class='menu-item-title'>{$title}</span>{$disabledBadge}
                <button type='button' class='btn btn-sm btn-outline-danger menu-item-remove' title='{$removeTitle}' {$removeDisabled}>Remove</button>
              </div>
              <div class='technical-only small text-muted'>Slug: {$slug}</div>
              {$childrenHtml}
            </li>";
        }
        return $html;
    }

    private static function jsonResponse(array $data, int $status = 200): Response
    {
        return Response::text(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
