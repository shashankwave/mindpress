(function($){
  'use strict';
  console.log('MindPress admin.js v0.3.3 loaded');

  const COLORS = [
    {key:'',       label:'None'},
    {key:'red',    label:'Red'},
    {key:'orange', label:'Orange'},
    {key:'yellow', label:'Yellow'},
    {key:'green',  label:'Green'},
    {key:'blue',   label:'Blue'},
    {key:'purple', label:'Purple'},
    {key:'gray',   label:'Gray'}
  ];

  const state = {
    data: { children: [] },   // virtual root
    selectedId: null,
    saveTimer: null
  };

  function uid(){ return 'n'+Math.random().toString(36).slice(2,10); }

  function parseInitial(){
    const raw = $('#mp-app').attr('data-json') || '';
    try {
      const parsed = raw ? JSON.parse(raw) : null;
      if (!parsed) {
        state.data = { children: [{ id: uid(), text: MindPress.i18n.root, notes:'', color:'', children:[] }] };
      } else if (Array.isArray(parsed)) {
        state.data = { children: parsed.map(normalizeNode) };
      } else if (parsed && typeof parsed === 'object') {
        if (Array.isArray(parsed.children)) state.data = { children: parsed.children.map(normalizeNode) };
        else if (parsed.text) state.data = { children: [ normalizeNode(parsed) ] };
        else state.data = { children: [] };
      }
    } catch(e){
      state.data = { children: [{ id: uid(), text: MindPress.i18n.root, notes:'', color:'', children:[] }] };
    }
    persistHidden();
  }

  function normalizeNode(n){
    if (!n) n = {};
    return {
      id: n.id || uid(),
      text: n.text || '',
      notes: n.notes || '',
      color: (typeof n.color === 'string') ? n.color : '',
      children: Array.isArray(n.children) ? n.children.map(normalizeNode) : []
    };
  }

  function persistHidden(){ $('#_mp_tree').val(JSON.stringify(state.data)); }

  function findNodeAndParent(id, nodes = state.data.children, parent = null){
    for (let i=0;i<nodes.length;i++){
      const n = nodes[i];
      if (n.id === id) return { node:n, parent, index:i, siblings:nodes };
      if (Array.isArray(n.children)){
        const r = findNodeAndParent(id, n.children, n);
        if (r) return r;
      }
    }
    return null;
  }

  function render(){
    const $app = $('#mp-app').empty();
    const $list = $('<ul class="mp-list root"></ul>');
    (state.data.children || []).forEach(n => $list.append(renderNode(n, 1)));
    $app.append($list);
  }

  function renderColorBar(n){
    const $bar = $('<div class="mp-colorbar" role="group" aria-label="Color"></div>');
    COLORS.forEach(c => {
      const $sw = $('<button type="button" class="mp-color-swatch" aria-pressed="false"></button>')
        .attr('data-color', c.key)
        .attr('title', c.label)
        .toggleClass('active', n.color === c.key);
      $bar.append($sw);
    });
    $bar.on('mousedown click', function(e){ e.stopPropagation(); });
    $bar.find('.mp-color-swatch').on('click', function(e){
      e.stopPropagation();
      const color = $(this).attr('data-color');
      n.color = color;
      // Update visual without full re-render
      const $card = $(this).closest('.mp-card');
      $card.attr('data-color', color);
      $(this).siblings().removeClass('active').attr('aria-pressed','false');
      $(this).addClass('active').attr('aria-pressed','true');
      scheduleSave(); // no rerender needed
    });
    return $bar;
  }

  function renderNode(n, depth){
    const $li   = $('<li class="mp-node"></li>').attr('data-id', n.id);
    const $card = $('<div class="mp-card"></div>')
                  .toggleClass('selected', state.selectedId===n.id)
                  .attr('data-color', n.color || '');

    const $text  = $('<input type="text" class="mp-text" />').val(n.text || '').attr('placeholder','Idea');
    const $notes = $('<textarea class="mp-notes" rows="2" placeholder="Notes..."></textarea>').val(n.notes || '');

    // toolbar: swatches + buttons
    const $tools = $('<div class="mp-tools"></div>');
    const $colors = renderColorBar(n);

    const $btns = $('<div class="mp-btns"></div>');
    const $addChild   = $('<button type="button" class="button button-small" title="'+MindPress.i18n.addChild+'">＋</button>');
    const $addSibling = $('<button type="button" class="button button-small" title="'+MindPress.i18n.addSibling+'">＝</button>');
    const $del        = $('<button type="button" class="button button-small" title="'+MindPress.i18n.delete+'">✕</button>');
    $btns.append($addChild, $addSibling, $del);

    $tools.append($colors, $btns);

    $card.append($text, $notes, $tools);
    $li.append($card);

    // children
    const kids = Array.isArray(n.children) ? n.children : [];
    const $kids = $('<ul class="mp-list"></ul>');
    kids.forEach(c => $kids.append(renderNode(c, depth+1)));
    $li.append($kids);

    // selection (no rerender)
    $card.on('mousedown', function(e){
      if ($(e.target).is('input,textarea,button,.button,.mp-color-swatch')) return;
      state.selectedId = n.id;
      $('.mp-card.selected').removeClass('selected');
      $(this).addClass('selected');
    });

    // stop bubbling while typing/clicking controls
    $text.on('mousedown click keydown focus', function(e){ e.stopPropagation(); });
    $notes.on('mousedown click keydown focus', function(e){ e.stopPropagation(); });
    $btns.on('mousedown click', function(e){ e.stopPropagation(); });

    // inputs update
    $text.on('input', () => { n.text = $text.val(); scheduleSave(); });
    $notes.on('input', () => { n.notes = $notes.val(); scheduleSave(); });

    // add child
    $addChild.on('click', () => {
      if (!Array.isArray(n.children)) n.children = [];
      n.children.push({ id: uid(), text: 'New idea', notes:'', color:'', children: [] });
      state.selectedId = n.children[n.children.length-1].id;
      scheduleSave(true);
    });

    // root-sibling fix
    $addSibling.on('click', () => {
      const found = findNodeAndParent(n.id);
      if (!found) return;
      const newNode = { id: uid(), text: 'New idea', notes:'', color:'', children: [] };
      if (found.parent === null) {
        state.data.children.splice(found.index+1, 0, newNode);
        state.selectedId = state.data.children[found.index+1].id;
      } else {
        found.siblings.splice(found.index+1, 0, newNode);
        state.selectedId = found.siblings[found.index+1].id;
      }
      scheduleSave(true);
    });

    // delete
    $del.on('click', () => {
      const found = findNodeAndParent(n.id);
      if (!found) return;
      found.siblings.splice(found.index,1);
      state.selectedId = null;
      if (state.data.children.length===0){
        state.data.children.push({ id: uid(), text: MindPress.i18n.root, notes:'', color:'', children: []});
      }
      scheduleSave(true);
    });

    return $li;
  }

  // autosave (debounced)
  function scheduleSave(rerender){
    persistHidden();
    if (rerender) render();
    if (state.saveTimer) clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(doSave, 400);
  }

  function doSave(){
    if (!MindPress.postId) return;
    $('#mp-status').text(MindPress.i18n.saving);
    $.post(MindPress.ajax, {
      action: 'mp_save_tree',
      nonce: MindPress.nonce,
      post_id: MindPress.postId,
      tree: JSON.stringify(state.data)
    }).always(function(){
      $('#mp-status').text(MindPress.i18n.saved);
      setTimeout(()=>$('#mp-status').text(''), 1000);
    });
  }

 // smart search: show matches + their ancestors, auto-expand
function applySearch(q){
  q = (q || '').toLowerCase().trim();

  const $nodes = $('.mp-node');
  // reset
  $nodes.removeAttr('data-match').show();
  $('.mp-card').removeClass('mp-hit');

  if (!q) { return; }

  // pass 1: mark matches and ancestors
  $nodes.each(function(){
    const $node = $(this);
    const $card = $node.children('.mp-card');
    const t = ($card.find('.mp-text').val()  || '').toLowerCase();
    const n = ($card.find('.mp-notes').val() || '').toLowerCase();
    if (t.includes(q) || n.includes(q)) {
      $node.attr('data-match','1');
      $card.addClass('mp-hit');
      // mark all ancestors so path stays visible
      $node.parents('li.mp-node').attr('data-match','1');
    }
  });

  // pass 2: hide non-matches, expand matched branches
  $nodes.each(function(){
    const $node = $(this);
    if ($node.attr('data-match') === '1') {
      $node.show().children('ul.mp-list').show(); // auto-expand branch
    } else {
      $node.hide();
    }
  });
}

$(document).on('input', '#mp-search', function(){
  applySearch($(this).val());
});


  // expand/collapse
  $(document).on('click', '#mp-expand-all',  ()=>$('.mp-list').show());
  $(document).on('click', '#mp-collapse-all', ()=>$('#mp-app > .mp-list.root > li > .mp-list').hide());

  // export/import
  $(document).on('click', '#mp-export', function(){
    const blob = new Blob([JSON.stringify(state.data, null, 2)], {type:'application/json'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download='mindpress.json'; a.click();
    alert(MindPress.i18n.exported);
  });
  $(document).on('click', '#mp-import', function(){
    const inp = document.createElement('input'); inp.type='file'; inp.accept='application/json';
    inp.onchange = e => {
      const f = e.target.files[0]; if (!f) return;
      const r = new FileReader();
      r.onload = () => {
        try{
          const obj = JSON.parse(r.result);
          if (Array.isArray(obj)) state.data = {children: obj.map(normalizeNode)};
          else if (obj && typeof obj==='object' && Array.isArray(obj.children)) state.data = {children: obj.children.map(normalizeNode)};
          else throw new Error('bad');
          persistHidden(); render(); scheduleSave();
        } catch(err){ alert(MindPress.i18n.importErr); }
      };
      r.readAsText(f);
    };
    inp.click();
  });

  // Generate NEW blog → open editor in NEW TAB
  $(document).on('click', '#mp-generate', function(){
    $.post(MindPress.ajax, {
      action: 'mp_generate_post',
      nonce: MindPress.nonce,
      source_id: MindPress.postId,
      tree: JSON.stringify(state.data)
    }).done(function(res){
      if (res && res.success && res.data && res.data.edit_link) {
        window.open(res.data.edit_link, '_blank');
      }
    });
  });

  // Insert into current post → open in NEW TAB
  $(document).on('click', '#mp-insert', function(){
    $.post(MindPress.ajax, {
      action: 'mp_insert_into',
      nonce: MindPress.nonce,
      source_id: MindPress.postId,
      post_id: MindPress.postId,
      tree: JSON.stringify(state.data)
    }).done(function(res){
      if (res && res.success && res.data && res.data.edit_link) {
        window.open(res.data.edit_link, '_blank');
      }
    });
  });

  $(function(){ parseInitial(); render(); });
})(jQuery);
