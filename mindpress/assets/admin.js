(function($){
  function nodeTemplate(text = '', notes = ''){
    return { text: text, notes: notes, children: [] };
  }

  function renderNode(node, level){
    const $li  = $('<li class="mp-node"></li>');
    const $row = $('<div class="mp-row"></div>');

    const $text  = $('<input type="text" class="mp-text" placeholder="Heading / idea">').val(node.text || '');
    const $notes = $('<textarea class="mp-notes" placeholder="Optional notes"></textarea>').val(node.notes || '');
    const $add   = $('<button type="button" class="button mp-add"></button>').text(MindPress.i18n.addChild);
    const $del   = $('<button type="button" class="button mp-del">×</button>');

    $row.append($text, $notes, $add, $del);
    $li.append($row);

    const $ul = $('<ul class="mp-children"></ul>');
    (node.children || []).forEach(child => $ul.append(renderNode(child, level+1)));
    $li.append($ul);

    // events
    $text.on('input',  ()=>{ node.text  = $text.val();  saveHidden(); });
    $notes.on('input', ()=>{ node.notes = $notes.val(); saveHidden(); });
    $add.on('click',   ()=>{ node.children.push(nodeTemplate()); $ul.append(renderNode(node.children[node.children.length-1], level+1)); saveHidden(); });
    $del.on('click',   ()=>{ if(confirm(MindPress.i18n.delete)){ $li.remove(); markDeleted(node); saveHidden(); }});

    return $li;
  }

  function markDeleted(node){ node._deleted = true; (node.children||[]).forEach(markDeleted); }

  function prune(node){
    if(node._deleted) return null;
    const prunedChildren = (node.children||[]).map(prune).filter(Boolean);
    return { text: node.text||'', notes: node.notes||'', children: prunedChildren };
  }

  function saveHidden(){
    if(!window.__mp_root) return;
    const pruned = prune(window.__mp_root);
    $('#_mp_tree').val(JSON.stringify(pruned));
  }

  $(function(){
    const $app = $('#mp-app');
    if(!$app.length) return;

    const initial = $app.data('json');
    // If initial is already an object (pre-parsed by jQuery), use it directly.
    // If it's a string, parse it.
    window.__mp_root = initial ? (typeof initial === 'string' ? JSON.parse(initial) : initial) : nodeTemplate(MindPress.i18n.root);

    const $tree = $('<ul class="mp-tree"></ul>');
    $tree.append(renderNode(window.__mp_root, 0));
    $app.append($tree);

    // Toolbar: expand/collapse
    $('#mp-expand-all').on('click', function(){
      $app.find('.mp-children').show();
    });
    $('#mp-collapse-all').on('click', function(){
      // Hide all children ULs within the tree.
      $app.find('.mp-tree .mp-children').hide();
    });

    // Toolbar: export
    $('#mp-export').on('click', function(){
      try {
        const pruned = prune(window.__mp_root);
        const json = JSON.stringify(pruned, null, 2);
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'mindpress-map.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        $('#mp-status').text(MindPress.i18n.exported).show().delay(2000).fadeOut();
      } catch(e) {
        console.error(e);
        alert('Export failed');
      }
    });

    // Toolbar: import
    $('#mp-import').on('click', function(){
      const $input = $('<input type="file" accept="application/json" />');
      $input.on('change', function(e){
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
          try {
            const data = JSON.parse(e.target.result);
            if (typeof data.text !== 'string' || !Array.isArray(data.children)) {
              throw new Error('Invalid format');
            }
            window.__mp_root = data;
            $tree.empty().append(renderNode(window.__mp_root, 0));
            saveHidden();
          } catch (err) {
            alert(MindPress.i18n.importErr);
          }
        };
        reader.readAsText(file);
      });
      $input.click();
    });

    // Toolbar: search
    $('#mp-search').on('input', function() {
        const query = $(this).val().toLowerCase().trim();
        const $nodes = $app.find('.mp-node');
        $nodes.find('.mp-text').css('background-color', '');
        if (query === '') {
            $nodes.show();
            return;
        }
        $nodes.hide();
        $nodes.each(function() {
            const $node = $(this);
            const text = $node.find('.mp-text').first().val().toLowerCase();
            const notes = $node.find('.mp-notes').first().val().toLowerCase();
            if (text.includes(query) || notes.includes(query)) {
                $node.show();
                $node.parentsUntil('.mp-tree', '.mp-node').show();
                $node.find('.mp-text').first().css('background-color', 'yellow');
            }
        });
    });

    // Generate Draft Post
    $('#mp-generate').on('click', function(){
      const pruned = prune(window.__mp_root);
      const title  = $('#title').val() || $('input[name="post_title"]').val() || 'MindPress Draft';
      $.post(MindPress.ajax, {
        action: 'mp_generate_post',
        nonce:  MindPress.nonce,
        tree:   JSON.stringify(pruned),
        source_id: MindPress.postId,
        title
      }).done(function(res){
        if(res && res.success){
          alert('Draft created! Opening editor…');
          window.open(res.data.edit_link, '_blank');
        } else {
          alert('Failed: ' + (res && res.data && res.data.message ? res.data.message : 'Unknown error'));
        }
      }).fail(function(){ alert('Request failed'); });
    });

    // Insert into current post
    $('#mp-insert').on('click', function(){
      if (!confirm(MindPress.i18n.insert)) return;
      const pruned = prune(window.__mp_root);
      $.post(MindPress.ajax, {
        action:    'mp_insert_into',
        nonce:     MindPress.nonce,
        post_id:   MindPress.postId,
        source_id: MindPress.ptype === 'post' ? MindPress.postId : 0,
        tree:      JSON.stringify(pruned)
      }).done(function(res){
        if(res && res.success){
          $('#mp-status').text(MindPress.i18n.saved).show().delay(2000).fadeOut();
          if(wp.data && wp.data.dispatch('core/editor')){
            wp.data.dispatch('core/editor').editPost({ content: res.data.content });
          } else if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            tinymce.get('content').setContent(res.data.content);
          } else {
            $('#content').val(res.data.content);
          }
        } else {
          alert('Failed: ' + (res && res.data && res.data.message ? res.data.message : 'Unknown error'));
        }
      }).fail(function(){ alert('Request failed'); });
    });

    // Initial save
    saveHidden();
  });
})(jQuery);