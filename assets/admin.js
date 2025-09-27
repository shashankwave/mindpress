(function($){
  console.log("MindPress admin.js loaded");
  function nodeTemplate(text = '', notes = ''){
    return { text: text, notes: notes, children: [] };
  }

  function renderNode(node, level){
    const $li  = $('<li class="mp-node"></li>');
    const $row = $('<div class="mp-row"></div>');

    const $text  = $('<input type="text" class="mp-text" placeholder="Heading / idea">').val(node.text || '');
    const $notes = $('<textarea class="mp-notes" placeholder="Optional notes"></textarea>').val(node.notes || '');
    const $add   = $('<button type="button" class="button mp-add">+ child</button>');
    const $del   = $('<button type="button" class="button mp-del">×</button>');

    $row.append($text, $notes, $add, $del);
    $li.append($row);

    const $ul = $('<ul class="mp-children"></ul>');
    (node.children || []).forEach(child => $ul.append(renderNode(child, level+1)));
    $li.append($ul);

    $text.on('input',  ()=>{ node.text  = $text.val();  saveHidden(); });
    $notes.on('input', ()=>{ node.notes = $notes.val(); saveHidden(); });
    $add.on('click',   ()=>{ node.children.push(nodeTemplate()); $ul.append(renderNode(node.children[node.children.length-1], level+1)); saveHidden(); });
    $del.on('click',   ()=>{ if(confirm('Delete this node?')){ $li.remove(); markDeleted(node); saveHidden(); }});

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
    window.__mp_root = initial ? JSON.parse(initial) : nodeTemplate('Root Idea');

    const $tree = $('<ul class="mp-tree"></ul>');
    $tree.append(renderNode(window.__mp_root, 0));
    $app.append($tree);

    $('#mp-generate').on('click', function(){
      const pruned = prune(window.__mp_root);
      const title  = $('#title').val() || $('input[name="post_title"]').val() || 'MindPress Draft';
      $.post(MindPress.ajax, {
        action: 'mp_generate_post',
        nonce:  MindPress.nonce,
        tree:   JSON.stringify(pruned),
        title
      }).done(function(res){
        if(res && res.success){
          alert('Draft created! Opening editor…');
          window.location.href = res.data.edit_link;
        } else {
          alert('Failed: ' + (res && res.data && res.data.message ? res.data.message : 'Unknown error'));
        }
      }).fail(function(){ alert('Request failed'); });
    });

    saveHidden();
  });
})(jQuery);
