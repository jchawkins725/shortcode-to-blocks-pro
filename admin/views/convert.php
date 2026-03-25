<?php
// admin/views/convert.php
defined('ABSPATH') || exit;
?>
<?php \STB\admin\Admin::render_tabs( (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-convert' ); ?>

<div class="wrap">
  <h1><?php esc_html_e('Batch convert', 'shortcode-to-blocks-pro'); ?></h1>

  <?php
  $selected_type = $pref && isset($counts[$pref]) ? $pref : '';
  $has_any = array_sum(array_column($counts, 'vc_total')) > 0;
  if (!$has_any): ?>
    <div class="notice notice-info">
      <p><?php esc_html_e('No posts detected with WPBakery content yet. Run a scan first on the Tools page.', 'shortcode-to-blocks-pro'); ?>
      <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-ajax.php?action=stbp_scan_vc'), 'stbp_convert_nonce','stbp_convert_nonce_field') ); ?>">
        <?php esc_html_e('Scan for WPBakery content', 'shortcode-to-blocks-pro'); ?>
      </a></p>
    </div>
  <?php endif; ?>

  <style>
    .stbp-grid{display:grid;grid-template-columns:1fr;gap:16px}
    @media (min-width:1100px){.stbp-grid{grid-template-columns:2fr 1fr}}
    .stbp-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px}
    .stbp-muted{color:#646970}
    .stbp-row{border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;background:#fff}
    .stbp-hd{display:flex;justify-content:space-between;align-items:center;gap:8px}
    .stbp-bar{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin-top:6px}
    .stbp-bar>span{display:block;height:100%;background:#007cba;width:0%}
    .stbp-badges{display:flex;gap:6px;flex-wrap:wrap}
    .stbp-badge{background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;padding:2px 8px;font-size:12px}
    .stbp-actions{display:flex;gap:8px;flex-wrap:wrap}
    .stbp-small{font-size:12px}
    .stbp-help{cursor:help;border-bottom:1px dotted #50575e}
    .stbp-list{max-height:380px;overflow:auto;margin-top:8px}
    .stbp-sticky{position:sticky;top:32px;z-index:10}
  </style>

  <div class="stbp-grid">
    <div>
      <div class="stbp-card">
        <form id="stbp-batch" action="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" method="post">
          <?php wp_nonce_field('stbp_convert_nonce','stbp_convert_nonce_field'); ?>
          <input type="hidden" name="action" value="stbp_batch_convert">
          <input type="hidden" name="batch_id" value="">
          <h2 style="margin:0 0 8px"><?php esc_html_e('Select post types', 'shortcode-to-blocks-pro'); ?></h2>
          <p class="stbp-muted stbp-small" style="margin-top:0;margin-bottom:12px">
            <?php esc_html_e('Only posts containing WPBakery shortcodes will be converted.', 'shortcode-to-blocks-pro'); ?>
          </p>
          <div class="stbp-actions stbp-small" style="margin-bottom:8px">
            <button type="button" class="button-link" id="stbp-select-all"><?php esc_html_e('Select all', 'shortcode-to-blocks-pro'); ?></button>
            <span aria-hidden="true">·</span>
            <button type="button" class="button-link" id="stbp-select-none"><?php esc_html_e('Select none', 'shortcode-to-blocks-pro'); ?></button>
          </div>


          <?php foreach ($counts as $type => $info): ?>
            <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
              <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type); ?>" <?php checked($selected_type === $type); ?>>
              <span><strong><?php echo esc_html($info['label']); ?></strong></span>
              <span class="stbp-badge stbp-muted"><?php echo (int)$info['vc_total']; ?> <?php esc_html_e('posts', 'shortcode-to-blocks-pro'); ?></span>
            </label>
          <?php endforeach; ?>

          <div class="stbp-field">
            <label class="screen-reader-text" for="stbp-limit-mode"><?php esc_html_e('How many to convert?', 'shortcode-to-blocks-pro'); ?></label>
            <fieldset id="stbp-limit-mode">
              <legend><?php esc_html_e('How many to convert?', 'shortcode-to-blocks-pro'); ?></legend>
              <label>
                <input type="radio" name="limit_mode" value="all" checked>
                <?php esc_html_e('Convert all', 'shortcode-to-blocks-pro'); ?>
              </label>
              &nbsp;&nbsp;
              <label>
                <input type="radio" name="limit_mode" value="cap">
                <?php esc_html_e('Limit to', 'shortcode-to-blocks-pro'); ?>
              </label>
              <input type="number" id="stbp-limit" name="limit" min="1" step="1" value="100" style="width:90px" aria-label="<?php esc_attr_e('Limit count', 'shortcode-to-blocks-pro'); ?>" disabled>
              <span><?php esc_html_e('items', 'shortcode-to-blocks-pro'); ?></span>
            </fieldset>
          </div>

          <p style="margin-top:12px">
            <label>
              <input type="checkbox" name="dry_run" value="1" checked>
              <?php esc_html_e('Dry run (no changes)', 'shortcode-to-blocks-pro'); ?>
              <span class="stbp-help" title="<?php echo esc_attr__('Simulates conversion without saving changes. At the end, you can download a CSV of posts that would change.', 'shortcode-to-blocks-pro'); ?>">?</span>
            </label>
          </p>

          <div class="stbp-actions" style="margin-top:12px">
            <button type="submit" class="button button-primary" id="stbp-start" <?php disabled(!$has_any); ?>>
              <?php esc_html_e('Start', 'shortcode-to-blocks-pro'); ?>
            </button>
            <button type="button" class="button" id="stbp-cancel" disabled>
              <?php esc_html_e('Cancel', 'shortcode-to-blocks-pro'); ?>
            </button>
          </div>

          <p class="stbp-muted stbp-small" style="margin-top:6px">
            <?php esc_html_e('Conversion runs in chunks to avoid timeouts. You can keep this tab open and work elsewhere.', 'shortcode-to-blocks-pro'); ?>
          </p>
        </form>
      </div>

      <div class="stbp-card stbp-sticky" id="stbp-progress" style="display:none;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 style="margin:0"><?php esc_html_e('Overall progress', 'shortcode-to-blocks-pro'); ?></h2>
          <button type="button" class="stbp-close-card" data-target="stbp-progress" style="background:none;border:none;font-size:18px;cursor:pointer;color:#666;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center" title="<?php esc_attr_e('Close', 'shortcode-to-blocks-pro'); ?>">&times;</button>
        </div>
        <div class="stbp-badges stbp-small">
          <span class="stbp-badge">
            <strong id="stbp-done-total">0</strong> / <span id="stbp-total-all">0</span> <?php esc_html_e('posts', 'shortcode-to-blocks-pro'); ?>
          </span>
          <span class="stbp-badge"><?php esc_html_e('ETA', 'shortcode-to-blocks-pro'); ?>: <span id="stbp-eta">—</span></span>
          <span class="stbp-badge"><?php esc_html_e('Speed', 'shortcode-to-blocks-pro'); ?>: <span id="stbp-speed">—</span></span>
        </div>
        <div class="stbp-bar" aria-hidden="true"><span id="stbp-overall-bar"></span></div>
      </div>

      <div class="stbp-card" id="stbp-types-card" style="display:none;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 style="margin:0"><?php esc_html_e('Per-type progress', 'shortcode-to-blocks-pro'); ?></h2>
          <button type="button" class="stbp-close-card" data-target="stbp-types-card" style="background:none;border:none;font-size:18px;cursor:pointer;color:#666;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center" title="<?php esc_attr_e('Close', 'shortcode-to-blocks-pro'); ?>">&times;</button>
        </div>
        <div class="stbp-list" id="stbp-type-rows">
          <?php foreach ($counts as $type => $info): ?>
            <div class="stbp-row" data-type="<?php echo esc_attr($type); ?>">
              <div class="stbp-hd">
                <div>
                  <strong><?php echo esc_html($info['label']); ?></strong>
                  <div class="stbp-small stbp-muted">
                    <span class="stbp-done">0</span> / <span class="stbp-total"><?php echo (int)$info['vc_total']; ?></span>
                    ( <span class="stbp-pct">0</span>% )
                  </div>
                </div>
                <div class="stbp-small stbp-muted stbp-status"><?php esc_html_e('Queued', 'shortcode-to-blocks-pro'); ?></div>
              </div>
              <div class="stbp-bar"><span></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="stbp-card" id="stbp-summary-card" style="display:none;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 style="margin:0"><?php esc_html_e('Summary', 'shortcode-to-blocks-pro'); ?></h2>
          <button type="button" class="stbp-close-card" data-target="stbp-summary-card" style="background:none;border:none;font-size:18px;cursor:pointer;color:#666;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center" title="<?php esc_attr_e('Close', 'shortcode-to-blocks-pro'); ?>">&times;</button>
        </div>
        <div id="stbp-summary" class="stbp-summary"></div>
      </div>

      <div class="stbp-card" style="margin-top:24px">
        <form id="stbp-parent-batch" action="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" method="post">
          <?php wp_nonce_field('stbp_convert_nonce','stbp_convert_nonce_field'); ?>
          <input type="hidden" name="action" value="stbp_parent_batch_convert">
          <input type="hidden" name="batch_id" value="">
          <h2 style="margin:0 0 8px"><?php esc_html_e('Convert Parent and All Descendants', 'shortcode-to-blocks-pro'); ?></h2>
          <div class="stbp-field" style="margin-bottom:12px;">
            <label for="stbp-parent-type"><strong><?php esc_html_e('Select post type', 'shortcode-to-blocks-pro'); ?></strong></label>
            <select name="parent_type" id="stbp-parent-type" style="min-width:180px">
              <option value="">-- <?php esc_html_e('Select type', 'shortcode-to-blocks-pro'); ?> --</option>
              <?php foreach ($counts as $type => $info): ?>
                <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($info['label']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="stbp-field" id="stbp-parent-field2" style="margin-bottom:12px;">
            <label for="stbp-parent-id2"><strong><?php esc_html_e('Select parent', 'shortcode-to-blocks-pro'); ?></strong></label>
            <select name="parent_id" id="stbp-parent-id2" style="min-width:220px">
              <option value="">-- <?php esc_html_e('None', 'shortcode-to-blocks-pro'); ?> --</option>
            </select>
            <span class="stbp-muted stbp-small" style="margin-left:8px"><?php esc_html_e('Convert parent and all descendants in one batch.', 'shortcode-to-blocks-pro'); ?></span>
          </div>
          <p style="margin-top:12px">
            <label>
              <input type="checkbox" name="parent_dry_run" value="1" checked>
              <?php esc_html_e('Dry run (no changes)', 'shortcode-to-blocks-pro'); ?>
              <span class="stbp-help" title="<?php echo esc_attr__('Simulates conversion without saving changes. Shows which posts would be converted.', 'shortcode-to-blocks-pro'); ?>">?</span>
            </label>
          </p>
          <div class="stbp-actions" style="margin-top:12px">
            <button type="submit" class="button button-primary" id="stbp-parent-start">
              <?php esc_html_e('Convert Tree', 'shortcode-to-blocks-pro'); ?>
            </button>
          </div>
        </form>
      </div>

      <div class="stbp-card" id="stbp-parent-progress" style="display:none;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 style="margin:0"><?php esc_html_e('Parent Tree Progress', 'shortcode-to-blocks-pro'); ?></h2>
          <button type="button" class="stbp-close-card" data-target="stbp-parent-progress" style="background:none;border:none;font-size:18px;cursor:pointer;color:#666;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center" title="<?php esc_attr_e('Close', 'shortcode-to-blocks-pro'); ?>">&times;</button>
        </div>
        <div class="stbp-badges stbp-small" style="margin-bottom:8px">
          <span class="stbp-badge">
            <span id="stbp-parent-status"><?php esc_html_e('Processing...', 'shortcode-to-blocks-pro'); ?></span>
          </span>
          <span class="stbp-badge">
            <span id="stbp-parent-processed">0</span> / <span id="stbp-parent-total">0</span> <?php esc_html_e('posts', 'shortcode-to-blocks-pro'); ?>
          </span>
        </div>
        <div class="stbp-bar" aria-hidden="true">
          <span id="stbp-parent-bar" style="width:0%"></span>
        </div>
        <div id="stbp-parent-details" style="margin-top:8px;font-size:12px;color:#646970"></div>
      </div>

      <div class="stbp-card" id="stbp-parent-summary" style="display:none;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h2 style="margin:0"><?php esc_html_e('Parent Tree Results', 'shortcode-to-blocks-pro'); ?></h2>
          <button type="button" class="stbp-close-card" data-target="stbp-parent-summary" style="background:none;border:none;font-size:18px;cursor:pointer;color:#666;padding:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center" title="<?php esc_attr_e('Close', 'shortcode-to-blocks-pro'); ?>">&times;</button>
        </div>
        <div id="stbp-parent-summary-content"></div>
      </div>

      <script>
      // Dynamic parent dropdown for parent batch card
      document.addEventListener('DOMContentLoaded', function() {
        var parentTypeDropdown = document.getElementById('stbp-parent-type');
        var parentDropdown2 = document.getElementById('stbp-parent-id2');
        function updateParentDropdown2() {
          var type = parentTypeDropdown.value;
          parentDropdown2.disabled = !type;
          if (type) {
            parentDropdown2.innerHTML = '<option value="">-- <?php esc_html_e('Loading...', 'shortcode-to-blocks-pro'); ?> --</option>';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
              var noneOpt = '<option value="">-- <?php esc_html_e('None', 'shortcode-to-blocks-pro'); ?> --</option>';
              if (xhr.status === 200) {
                try {
                  var data = JSON.parse(xhr.responseText);
                  var opts = noneOpt;
                  if (data.success && data.data && data.data.parents && data.data.parents.length) {
                    data.data.parents.forEach(function(p) {
                      var indent = '';
                      if (p.depth && p.depth > 0) {
                        indent = Array(p.depth + 1).join('&nbsp;&nbsp;&nbsp;');
                      }
                      opts += '<option value="' + p.ID + '">' + indent + p.post_title + ' (#' + p.ID + ')</option>';
                    });
                  }
                  parentDropdown2.innerHTML = opts;
                } catch(e) {
                  parentDropdown2.innerHTML = noneOpt;
                  console.error('STBP parent dropdown parse error', e);
                }
              } else {
                parentDropdown2.innerHTML = noneOpt;
                console.error('STBP parent dropdown HTTP error', xhr.status);
              }
            };
            xhr.onerror = function() {
              parentDropdown2.innerHTML = '<option value="">-- <?php esc_html_e('None', 'shortcode-to-blocks-pro'); ?> --</option>';
              console.error('STBP parent dropdown network error');
            };
            xhr.send('action=stbp_get_parents&type=' + encodeURIComponent(type) + '&_ajax_nonce=<?php echo wp_create_nonce('stbp_get_parents'); ?>');
          } else {
            parentDropdown2.innerHTML = '<option value="">-- <?php esc_html_e('None', 'shortcode-to-blocks-pro'); ?> --</option>';
          }
        }
        parentTypeDropdown.addEventListener('change', updateParentDropdown2);
        updateParentDropdown2();
      });
      </script>
    </div>

    <div>
      <div class="stbp-card">
        <h2 style="margin:0 0 8px"><?php esc_html_e('Tips', 'shortcode-to-blocks-pro'); ?></h2>
        <ul class="stbp-muted stbp-small" style="margin:0;padding-left:18px;list-style:disc">
          <li><?php esc_html_e('Use Dry run first to see which posts would change and download a CSV report.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('Parent Tree conversion processes a parent post and all its descendants in one batch.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('Each conversion gets a unique batch ID for tracking and potential reverting.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('If you cancel a batch conversion, you can resume later — already converted posts are skipped.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('Run a new scan from Tools if you add new post types or WPBakery content.', 'shortcode-to-blocks-pro'); ?></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(function($){
  console.log('[STBP] convert view JS loaded');

  const $form       = $('#stbp-batch');
  const $panel      = $('#stbp-progress');
  const $typesCard  = $('#stbp-types-card');
  const $summaryCard= $('#stbp-summary-card');
  const $summary    = $('#stbp-summary');
  const $rows       = $('#stbp-type-rows .stbp-row');
  const $cancelBtn  = $('#stbp-cancel');

  // Select all / none
  $(document).on('click', '#stbp-select-all', function(e){
    e.preventDefault();
    $('#stbp-batch input[name="post_types[]"]').prop('checked', true).trigger('change');
  });
  $(document).on('click', '#stbp-select-none', function(e){
    e.preventDefault();
    $('#stbp-batch input[name="post_types[]"]').prop('checked', false).trigger('change');
  });

  // limit toggle
  $(document).on('change', 'input[name="limit_mode"]', function () {
    const cap = $('input[name="limit_mode"]:checked').val() === 'cap';
    $('#stbp-limit').prop('disabled', !cap);
  });

  // Build totals
  const totals = {};
  $rows.each(function(){
    const type  = $(this).data('type');
    const total = parseInt($(this).find('.stbp-total').text(), 10) || 0;
    totals[type] = total;
  });

  // Row helpers
  function markWorking(type){ $rows.filter('[data-type="'+type+'"]').find('.stbp-status').text('<?php echo esc_js(__('Working…','shortcode-to-blocks-pro')); ?>'); }
  function markFinished(type){ updateRow(type, totals[type] || 0); $rows.filter('[data-type="'+type+'"]').find('.stbp-status').text('<?php echo esc_js(__('Finished','shortcode-to-blocks-pro')); ?>'); }
  function updateRow(type, done){
    const $row = $rows.filter('[data-type="'+type+'"]');
    const total = totals[type] || 0;
    const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    $row.find('.stbp-done').text(done);
    $row.find('.stbp-pct').text(pct);
    $row.find('.stbp-bar > span').css('width', pct + '%');
  }

  // Overall progress
  let processedOverall = 0;
  let startedAt = 0;
  const $overallDone  = $('#stbp-done-total');
  const $overallTotal = $('#stbp-total-all');
  const $overallBar   = $('#stbp-overall-bar');
  const $eta          = $('#stbp-eta');
  const $speed        = $('#stbp-speed');

  function computeOverallTotal(selected){
    let total = 0; selected.forEach(t => total += (totals[t] || 0));
    $overallTotal.text(total);
    return total;
  }
  function updateOverall(processed, overallTotal){
    processedOverall += processed;
    const pct = overallTotal > 0 ? Math.min(100, Math.round((processedOverall / overallTotal) * 100)) : 0;
    $overallDone.text(processedOverall);
    $overallBar.css('width', pct + '%');

    const elapsed = (Date.now() - startedAt) / 1000;
    if (elapsed > 2 && processedOverall > 0) {
      const perSec = processedOverall / elapsed;
      $speed.text(perSec.toFixed(1) + ' / s');
      const remaining = Math.max(0, overallTotal - processedOverall);
      const etaSec = remaining / Math.max(0.1, perSec);
      const m = Math.floor(etaSec / 60), s = Math.round(etaSec % 60);
      $eta.text((m ? m+'m ' : '') + s + 's');
    } else { $speed.text('—'); $eta.text('—'); }
  }

  // Serialize form
  function formDataObj($f){
    const o = {};
    $.each($f.serializeArray(), function(_, kv){
      if (kv.name.endsWith('[]')) {
        const key = kv.name.slice(0,-2);
        (o[key] = o[key] || []).push(kv.value);
      } else { o[kv.name] = kv.value; }
    });
    return o;
  }

  let cancelled = false;

  // START conversion
  $form.on('submit', function(e){
    e.preventDefault();

    const dataBase = formDataObj($form);
    const selected = (dataBase['post_types'] || []);
    if (!selected.length) {
      alert('<?php echo esc_js(__('Select at least one post type.','shortcode-to-blocks-pro')); ?>');
      return;
    }

    // fresh batch id
    const batchId = 'b_' + (Date.now().toString(36)) + '_' + Math.random().toString(36).slice(2,8);
    $form.find('input[name="batch_id"]').val(batchId);

    // lock UI
    $form.find('input,select,button').prop('disabled', true);
    $cancelBtn.prop('disabled', false);
    cancelled = false;

    // show progress
    $('#stbp-progress, #stbp-types-card').show();
    $('#stbp-summary-card').hide().find('#stbp-summary').empty();

    // reset rows
    $rows.each(function(){
      $(this).find('.stbp-done').text('0');
      $(this).find('.stbp-pct').text('0');
      $(this).find('.stbp-bar > span').css('width','0%');
      $(this).find('.stbp-status').text('<?php echo esc_js(__('Queued','shortcode-to-blocks-pro')); ?>');
    });

    // overall
    processedOverall = 0;
    startedAt = Date.now();
    const overallTotal = computeOverallTotal(selected);
    updateOverall(0, overallTotal);

    // choose first checked from selection order
    let idx = 0;
    for (let i=0;i<selected.length;i++){
      const $chk = $form.find('input[name="post_types[]"][value="'+selected[i]+'"]');
      if ($chk.is(':checked')) { idx = i; break; }
    }
    let current = selected[idx];
    let offset = 0;
    let doneForType = 0;
    const limitMode = $('input[name="limit_mode"]:checked').val();
    const limitVal  = parseInt($('#stbp-limit').val(), 10);
    const limit = (limitMode === 'cap' && Number.isFinite(limitVal) && limitVal > 0) ? limitVal : 0;

    markWorking(current);

    function tick(){
      if (cancelled) {
        $form.find('input,select,button').prop('disabled', false);
        $cancelBtn.prop('disabled', true);
        return;
      }

      const payload = $.extend({}, dataBase, {
        action: 'stbp_batch_convert',
        current_type: current,
        offset: offset,
        batch_id: batchId,
        limit: limit
      });

      $.post(ajaxurl, payload).done(function(resp){
        if (!resp || resp.success !== true) {
          const msg = (resp && resp.data) ? resp.data : 'Error';
          $('#stbp-summary-card').show();
          $('#stbp-summary').html('<div class="notice notice-error"><p>'+ msg +'</p></div>');
          $form.find('input,select,button').prop('disabled', false);
          $cancelBtn.prop('disabled', true);
          return;
        }

        const d = resp.data || {};
        const processed = parseInt(d.processed || 0, 10);

        doneForType += processed;
        updateRow(current, doneForType);
        updateOverall(processed, overallTotal);

        if (typeof d.next_type === 'string' && d.next_type !== current) {
          // finished this type
          markFinished(current);
          current = d.next_type;
          offset = d.next_offset || 0;
          doneForType = 0;
          markWorking(current);
          setTimeout(tick, 120);
          return;
        }

        if (typeof d.next_type === 'string') {
          // continue same type
          offset = d.next_offset || (offset + 20);
          setTimeout(tick, 120);
          return;
        }

        // all done
        markFinished(current);
        $form.find('input,select,button').prop('disabled', false);
        $cancelBtn.prop('disabled', true);

        $('#stbp-summary-card').show();
        let html = '';
        if (d.download_csv) {
          html += '<div class="notice notice-success"><p><?php echo esc_js(__('Dry run finished.', 'shortcode-to-blocks-pro')); ?></p></div>';
          html += '<p><a class="button button-primary" href="'+ d.download_csv +'"><?php echo esc_js(__('Download dry-run CSV','shortcode-to-blocks-pro')); ?></a></p>';
          if (d.summary) {
            html += '<table class="widefat striped" style="margin-top:10px"><thead><tr><th><?php echo esc_js(__('Type','shortcode-to-blocks-pro')); ?></th><th><?php echo esc_js(__('Would change','shortcode-to-blocks-pro')); ?></th><th><?php echo esc_js(__('No change','shortcode-to-blocks-pro')); ?></th><th><?php echo esc_js(__('Errors','shortcode-to-blocks-pro')); ?></th></tr></thead><tbody>';
            for (const t in d.summary) {
              const s = d.summary[t];
              html += '<tr><td>'+ t +'</td><td>'+ (s.would_change||0) +'</td><td>'+ (s.no_change||0) +'</td><td>'+ (s.errors||0) +'</td></tr>';
            }
            html += '</tbody></table>';
          }
        } else {
          html += '<div class="notice notice-success"><p><?php echo esc_js(__('Batch conversion finished.', 'shortcode-to-blocks-pro')); ?></p></div>';

          if (d.batch_id) {
            html += '<p class="stbp-small" style="display:flex;align-items:center;gap:8px;">'
                  + '<?php echo esc_js(__('Batch id:', 'shortcode-to-blocks-pro')); ?> '
                  + '<code id="stbp-finished-batch-id">'+ d.batch_id +'</code>'
                  + '<button type="button" class="button button-small" id="stbp-copy-batch"><?php echo esc_js(__('Copy', 'shortcode-to-blocks-pro')); ?></button>'
                  + '</p>';
          }

          // (copy updated to reference the Revert submenu rather than "below")
          html += '<p class="stbp-muted stbp-small"><?php echo esc_js(__('Tip: you can revert this batch from the “Revert” submenu.', 'shortcode-to-blocks-pro')); ?></p>';
        }
        $('#stbp-summary').html(html);

      }).fail(function(){
        $('#stbp-summary-card').show();
        $('#stbp-summary').html('<div class="notice notice-error"><p><?php echo esc_js(__('Network error. Try again.','shortcode-to-blocks-pro')); ?></p></div>');
        $form.find('input,select,button').prop('disabled', false);
        $cancelBtn.prop('disabled', true);
      });
    }

    tick();
  });

  // Parent batch form submission
  const $parentForm = $('#stbp-parent-batch');
  $parentForm.on('submit', function(e){
    e.preventDefault();

    const parentType = $('#stbp-parent-type').val();
    const parentId = $('#stbp-parent-id2').val();

    if (!parentType) {
      alert('<?php echo esc_js(__('Please select a post type.','shortcode-to-blocks-pro')); ?>');
      return;
    }
    if (!parentId) {
      alert('<?php echo esc_js(__('Please select a parent post.','shortcode-to-blocks-pro')); ?>');
      return;
    }

    // Generate batch ID
    const batchId = 'pb_' + (Date.now().toString(36)) + '_' + Math.random().toString(36).slice(2,8);
    $parentForm.find('input[name="batch_id"]').val(batchId);

    // Lock UI
    $parentForm.find('input,select,button').prop('disabled', true);
    
    // Show dedicated progress card
    $('#stbp-parent-progress').show();
    $('#stbp-parent-status').text('<?php echo esc_js(__('Processing...','shortcode-to-blocks-pro')); ?>');
    $('#stbp-parent-processed').text('0');
    $('#stbp-parent-total').text('?');
    $('#stbp-parent-bar').css('width', '0%');
    $('#stbp-parent-details').text('<?php echo esc_js(__('Finding posts with WPBakery content...','shortcode-to-blocks-pro')); ?>');

    const isDryRun = $parentForm.find('input[name="parent_dry_run"]').is(':checked');
    
    const payload = {
      action: 'stbp_parent_batch_convert',
      stbp_convert_nonce_field: $parentForm.find('input[name="stbp_convert_nonce_field"]').val(),
      parent_type: parentType,
      parent_id: parentId,
      batch_id: batchId,
      dry_run: isDryRun ? '1' : ''
    };

    $.post(ajaxurl, payload).done(function(resp){
      if (!resp || resp.success !== true) {
        const msg = (resp && resp.data) ? resp.data : 'Error';
        $('#stbp-parent-status').text('<?php echo esc_js(__('Error','shortcode-to-blocks-pro')); ?>');
        $('#stbp-parent-details').html('<div style="color:#d63638">'+ msg +'</div>');
        $parentForm.find('input,select,button').prop('disabled', false);
        return;
      }

      const d = resp.data || {};
      
      // Update progress card with results
      $('#stbp-parent-status').text('<?php echo esc_js(__('Completed','shortcode-to-blocks-pro')); ?>');
      $('#stbp-parent-processed').text(d.processed || 0);
      $('#stbp-parent-total').text(d.total_found || 0);
      const pct = (d.total_found > 0) ? Math.round((d.processed / d.total_found) * 100) : 100;
      $('#stbp-parent-bar').css('width', pct + '%');
      
      const parentTitle = $('#stbp-parent-id2 option:selected').text();
      $('#stbp-parent-details').html('<?php echo esc_js(__('Parent:','shortcode-to-blocks-pro')); ?> ' + parentTitle);

      // Show summary card with detailed results
      $('#stbp-parent-summary').show();
      let html = '<div class="notice notice-success"><p>'+ (d.message || '<?php echo esc_js(__('Parent batch conversion completed.','shortcode-to-blocks-pro')); ?>') +'</p></div>';
      
      // Handle dry run results
      if (d.dry_run) {
        if (d.download_csv) {
          html += '<p><a class="button button-primary" href="'+ d.download_csv +'"><?php echo esc_js(__('Download Dry-Run CSV','shortcode-to-blocks-pro')); ?></a></p>';
        }
        
        if (d.summary) {
          html += '<table class="widefat striped" style="margin-top:10px"><thead><tr>';
          html += '<th><?php echo esc_js(__('Would Change','shortcode-to-blocks-pro')); ?></th>';
          html += '<th><?php echo esc_js(__('No Change','shortcode-to-blocks-pro')); ?></th>';
          html += '<th><?php echo esc_js(__('Errors','shortcode-to-blocks-pro')); ?></th>';
          html += '</tr></thead><tbody><tr>';
          html += '<td>'+ (d.summary.would_change || 0) +'</td>';
          html += '<td>'+ (d.summary.no_change || 0) +'</td>';
          html += '<td>'+ (d.summary.errors || 0) +'</td>';
          html += '</tr></tbody></table>';
        }
      } else {
        // Real conversion results
        if (d.batch_id) {
          html += '<p class="stbp-small" style="display:flex;align-items:center;gap:8px;">'
                + '<?php echo esc_js(__('Batch id:', 'shortcode-to-blocks-pro')); ?> '
                + '<code id="stbp-finished-batch-id">'+ d.batch_id +'</code>'
                + '<button type="button" class="button button-small" id="stbp-copy-batch"><?php echo esc_js(__('Copy', 'shortcode-to-blocks-pro')); ?></button>'
                + '</p>';
        }
        html += '<p class="stbp-muted stbp-small"><?php echo esc_js(__('Tip: you can revert this batch from the "Revert" submenu.', 'shortcode-to-blocks-pro')); ?></p>';
      }

      if (d.processed && d.total_found) {
        html += '<p class="stbp-small stbp-muted"><?php echo esc_js(__('Found:', 'shortcode-to-blocks-pro')); ?> ' + d.total_found + ' posts with WPBakery content</p>';
      }

      if (d.errors && d.errors.length > 0) {
        html += '<div class="notice notice-warning" style="margin-top:10px"><p><strong><?php echo esc_js(__('Some errors occurred:', 'shortcode-to-blocks-pro')); ?></strong></p>';
        html += '<ul style="margin:5px 0 0 20px;">';
        d.errors.slice(0, 5).forEach(function(error) {
          html += '<li>' + error + '</li>';
        });
        if (d.errors.length > 5) {
          html += '<li><em>... and ' + (d.errors.length - 5) + ' more</em></li>';
        }
        html += '</ul></div>';
      }

      $('#stbp-parent-summary-content').html(html);
      $parentForm.find('input,select,button').prop('disabled', false);

    }).fail(function(){
      $('#stbp-parent-status').text('<?php echo esc_js(__('Failed','shortcode-to-blocks-pro')); ?>');
      $('#stbp-parent-details').html('<div style="color:#d63638"><?php echo esc_js(__('Network error. Try again.','shortcode-to-blocks-pro')); ?></div>');
      $('#stbp-parent-summary').show();
      $('#stbp-parent-summary-content').html('<div class="notice notice-error"><p><?php echo esc_js(__('Network error. Try again.','shortcode-to-blocks-pro')); ?></p></div>');
      $parentForm.find('input,select,button').prop('disabled', false);
    });
  });

  // Cancel
  $(document).on('click', '#stbp-cancel', function(e){
    e.preventDefault();
    cancelled = true;
    $(this).prop('disabled', true);
  });

  $(document).off('click', '#stbp-copy-batch').on('click', '#stbp-copy-batch', function(){
    const id = $('#stbp-finished-batch-id').text();
    navigator.clipboard.writeText(id).then(() => {
      $(this).text('<?php echo esc_js(__('Copied!', 'shortcode-to-blocks-pro')); ?>');
      setTimeout(() => $(this).text('<?php echo esc_js(__('Copy', 'shortcode-to-blocks-pro')); ?>'), 1200);
    });
  });

  // Close card functionality
  $(document).on('click', '.stbp-close-card', function(e){
    e.preventDefault();
    const targetId = $(this).data('target');
    $('#' + targetId).hide();
  });

  // Safety
  if (typeof window.ajaxurl === 'undefined') {
    console.warn('[STBP] ajaxurl is undefined; WP should define it in admin. Using admin-ajax URL constant as fallback.');
  }
});
</script>