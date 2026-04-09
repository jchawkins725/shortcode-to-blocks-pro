<?php
// admin/views/revert.php
defined('ABSPATH') || exit;
?>
<?php \STB\admin\Admin::render_tabs( (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-revert' ); ?>

<div class="wrap">
  <h1><?php esc_html_e('Batch revert', 'shortcode-to-blocks-pro'); ?></h1>

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
    #stbp-view-batch-posts{text-decoration:none;color:#2271b1}
    #stbp-view-batch-posts:hover{color:#135e96}
  </style>

  <div class="stbp-grid">
    <div>
      <!-- Batch revert (moved from convert) -->
      <div class="stbp-card" id="stbp-revert-card">
        <h2 style="margin:0 0 8px"><?php esc_html_e('Revert by batch id or date', 'shortcode-to-blocks-pro'); ?></h2>
        <p class="stbp-muted stbp-small" style="margin-top:0">
          <?php esc_html_e('Revert posts that were converted in a batch. Scope by batch id or by a converted-after date.', 'shortcode-to-blocks-pro'); ?>
        </p>

        <fieldset style="border:1px solid #ddd; padding:16px; border-radius:8px;">
          <label><?php esc_html_e('Batch id', 'shortcode-to-blocks-pro'); ?>
            <input id="stbp-revert-batch-id" type="text" style="width:100%;" placeholder="e.g., 04a0e6c9-7a0f-4e8b-97a7-4a9e..." />
          </label>

          <div class="stbp-actions" style="gap:8px;margin-top:8px;">
            <button type="button" id="stbp-find-batches" class="button"><?php esc_html_e('Find recent batches', 'shortcode-to-blocks-pro'); ?></button>
            <select id="stbp-batch-picker" style="min-width:280px; display:none;"></select>
          </div>

          <div style="margin-top:8px;">
            <a href="#" id="stbp-view-batch-posts" style="display:none;"><?php esc_html_e('View posts from this batch', 'shortcode-to-blocks-pro'); ?> →</a>
          </div>

          <label><?php esc_html_e('Converted after (optional)', 'shortcode-to-blocks-pro'); ?>

            <input id="stbp-revert-after" type="datetime-local" />
          </label>

          <div style="margin-top:10px;">
            <button id="stbp-start-revert" class="button button-primary"><?php esc_html_e('Start revert', 'shortcode-to-blocks-pro'); ?></button>

            <?php if (!empty($last_batch['id'])): ?>
              <button id="stbp-undo-last" class="button"><?php
                // translators: %s: Batch ID
                echo esc_html(sprintf(__('Undo last batch (%s)', 'shortcode-to-blocks-pro'), $last_batch['id']));
              ?></button>
            <?php endif; ?>
          </div>

          <div id="stbp-revert-progress" style="margin-top:10px; display:none;">
            <span id="stbp-revert-msg"><?php esc_html_e('Starting…', 'shortcode-to-blocks-pro'); ?></span>
          </div>
        </fieldset>
      </div>

      <!-- Summary (same card style as convert) -->
      <div class="stbp-card" id="stbp-summary-card" style="display:none;margin-top:16px">
        <h2 style="margin:0 0 8px"><?php esc_html_e('Summary', 'shortcode-to-blocks-pro'); ?></h2>
        <div id="stbp-summary" class="stbp-summary"></div>
      </div>
    </div>

    <div>
      <!-- Tips (mirror convert page placement/style) -->
      <div class="stbp-card">
        <h2 style="margin:0 0 8px"><?php esc_html_e('Tips', 'shortcode-to-blocks-pro'); ?></h2>
        <ul class="stbp-muted stbp-small" style="margin:0;padding-left:18px;list-style:disc">
          <li><?php esc_html_e('Use a recent batch id for precise control, or a converted-after date to roll back a time window.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('Reverting restores the original WPBakery content from backups created during conversion.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('After revert, review posts that were edited since conversion to confirm content is as expected.', 'shortcode-to-blocks-pro'); ?></li>
          <li><?php esc_html_e('If you need to revert a specific post, use the revert option on that specific post.', 'shortcode-to-blocks-pro'); ?></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(function($){
  console.log('[STBP] revert view JS loaded');

  // ===== Revert JS (moved from convert) =====
  const ajaxurlPH = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
  const nonceKey  = "stbp_convert_nonce_field";
  const nonceVal  = "<?php echo isset($nonce) ? esc_js($nonce) : ''; ?>";

  function tsFromLocalInput(val){
    if(!val) return 0;
    const t = Date.parse(val);
    return isNaN(t) ? 0 : Math.floor(t/1000);
  }

  async function runRevert(params){
    $('#stbp-revert-progress').show();
    const res = await $.post(ajaxurlPH, Object.assign({ action: 'stbp_batch_revert' }, params));

    if(!res || !res.success){
      $('#stbp-revert-msg').text('Error: ' + (res && res.data ? res.data : 'unknown'));
      return;
    }

    const d = res.data || {};
    const processed = d.processed || 0;
    $('#stbp-revert-msg').text('Processed ' + processed + '…');

    if(d.done){
      $('#stbp-revert-msg').text('Revert complete. Processed ' + processed + ' in the last step.');
      // Optional: populate summary card with a small success note
      $('#stbp-summary-card').show();
      $('#stbp-summary').html('<div class="notice notice-success"><p><?php echo esc_js(__('Revert finished.', 'shortcode-to-blocks-pro')); ?></p></div>');
      return;
    }

    params.offset = d.next_offset || (params.offset + (params.per_page || 20));
    return runRevert(params);
  }

  // Handle limit mode radio buttons
  $('input[name="revert_limit_mode"]').on('change', function() {
    const isCapped = $(this).val() === 'cap';
    $('#stbp-revert-per-page').prop('disabled', !isCapped);
  });

  $('#stbp-start-revert').on('click', function(e){
    e.preventDefault();

    const batchId   = $('#stbp-revert-batch-id').val().trim();
    const afterTs   = tsFromLocalInput($('#stbp-revert-after').val());
    const limitMode = $('input[name="revert_limit_mode"]:checked').val();
    const perPage   = limitMode === 'cap' 
      ? Math.max(1, Math.min(200, parseInt($('#stbp-revert-per-page').val(), 10) || 1))
      : 0; // 0 means no batch size limit
    const totalLimit = limitMode === 'cap'
      ? Math.max(1, parseInt($('#stbp-revert-per-page').val(), 10) || 1)
      : 0; // 0 means no total limit

    if(!batchId && !afterTs){
      alert('<?php echo esc_js(__('Please provide a batch id or a converted-after date.','shortcode-to-blocks-pro')); ?>');
      return;
    }

    runRevert({
      [nonceKey]: nonceVal,
      batch_id: batchId,
      converted_after: afterTs,
      per_page: perPage,
      limit: totalLimit,
      offset: 0
    });
  });

  $('#stbp-undo-last').on('click', function(e){
    e.preventDefault();
    const lastId = "<?php echo !empty($last_batch['id']) ? esc_js($last_batch['id']) : ''; ?>";
    if(!lastId){
      alert('<?php echo esc_js(__('No last batch recorded.','shortcode-to-blocks-pro')); ?>');
      return;
    }
    $('#stbp-revert-batch-id').val(lastId);
    $('#stbp-revert-after').val('');
    $('#stbp-start-revert').trigger('click');
  });

  // Store valid batch IDs
  let validBatchIds = [];

  // Function to validate batch ID
  async function validateBatchId(batchId) {
    if (!batchId) return false;
    
    // If we already have the batch list, check against it
    if (validBatchIds.length > 0) {
      return validBatchIds.includes(batchId);
    }
    
    // Otherwise fetch the batch list
    try {
      const res = await $.post(ajaxurlPH, {
        action: 'stbp_list_batches',
        [nonceKey]: nonceVal
      });

      if (res?.success === true && res.data?.batches) {
        validBatchIds = res.data.batches.map(b => b.batch_id);
        return validBatchIds.includes(batchId);
      }
    } catch (err) {
      console.error('Error validating batch ID:', err);
    }
    return false;
  }

  // Fetch & show recent batch IDs
  $('#stbp-find-batches').on('click', async function(e){
    e.preventDefault();
    const $btn = $(this);
    $btn.prop('disabled', true).text('<?php echo esc_js(__('Loading…', 'shortcode-to-blocks-pro')); ?>');

    try {
      const res = await $.post(ajaxurlPH, {
        action: 'stbp_list_batches',
        [nonceKey]: nonceVal
      });

      if(!res || res.success !== true || !res.data || !Array.isArray(res.data.batches)) {
        alert('<?php echo esc_js(__('Could not load batches.', 'shortcode-to-blocks-pro')); ?>');
        return;
      }

      // Store valid batch IDs
      validBatchIds = res.data.batches.map(b => b.batch_id);

      const $sel = $('#stbp-batch-picker').empty().show();
      $sel.append('<option value=""><?php echo esc_js(__('Select a batch…', 'shortcode-to-blocks-pro')); ?></option>');

      res.data.batches.forEach(b => {
        const ts = b.last_ts ? new Date(b.last_ts * 1000).toLocaleString() : '—';
        const label = `${b.batch_id}  (${b.post_count} <?php echo esc_js(__('posts','shortcode-to-blocks-pro')); ?>, ${ts})`;
        $sel.append($('<option>', { value: b.batch_id, text: label }));
      });

      $sel.off('change').on('change', function(){
        const v = $(this).val();
        if (v) $('#stbp-revert-batch-id').val(v).trigger('input');
      });

    } finally {
      $btn.prop('disabled', false).text('<?php echo esc_js(__('Find recent batches', 'shortcode-to-blocks-pro')); ?>');
    }
  });

  // Handle batch ID input for view posts link
  $('#stbp-revert-batch-id').on('input', async function() {
    const batchId = $(this).val().trim();
    const $viewLink = $('#stbp-view-batch-posts');
    
    if (batchId && await validateBatchId(batchId)) {
      $viewLink.show().attr('href', '<?php echo esc_js(admin_url('admin.php?page=' . (defined('STB_SLUG') ? STB_SLUG : 'shortcode-to-blocks') . '-converted')); ?>&batch_id=' + encodeURIComponent(batchId));
    } else {
      $viewLink.hide();
    }
  });

  // Safety
  if (typeof window.ajaxurl === 'undefined') {
    console.warn('[STBP] ajaxurl is undefined; WP should define it in admin. Using admin-ajax URL constant as fallback.');
  }
});
</script>