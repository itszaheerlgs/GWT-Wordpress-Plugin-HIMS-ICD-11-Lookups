jQuery(function($){
    var cfg = window.himsICD11Admin || {};

    /* Test connection */
    $('#hims-test-btn').on('click', function(){
        var $btn = $(this).prop('disabled', true).text('Testing…');
        var $res = $('#hims-test-result').show().removeClass('success error').text('Connecting…');
        $.post(cfg.ajax_url, { action:'hims_icd11_test', nonce:cfg.nonce }, function(r){
            $res.addClass(r.success ? 'success' : 'error').text(r.data);
        }).always(function(){ $btn.prop('disabled', false).text('Test API Connection'); });
    });

    /* Flush cache */
    $('#hims-flush-btn').on('click', function(){
        if(!confirm('Flush all cached ICD-11 data?')) return;
        var $btn = $(this).prop('disabled', true);
        $.post(cfg.ajax_url, { action:'hims_icd11_flush_cache', nonce:cfg.nonce }, function(r){
            $('#hims-flush-result').show().addClass(r.success ? 'success hims-notice' : 'error hims-notice').text(r.data);
        }).always(function(){ $btn.prop('disabled', false); });
    });

    /* Clear all history */
    $('#hims-clear-history-btn').on('click', function(){
        if(!confirm('Clear all search history? This cannot be undone.')) return;
        $.post(cfg.ajax_url, { action:'hims_icd11_clear_history', nonce:cfg.nonce }, function(r){
            if(r.success) location.reload();
        });
    });

    /* Delete single history record */
    $(document).on('click', '.hims-del-history', function(){
        var id = $(this).data('id');
        $.post(cfg.ajax_url, { action:'hims_icd11_delete_history', nonce:cfg.nonce, record_id:id }, function(r){
            if(r.success) $('#hims-history-row-'+id).fadeOut(300, function(){ $(this).remove(); });
        });
    });
});
