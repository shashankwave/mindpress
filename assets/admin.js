 (function($){
  const COLORS = ["blue","green","amber","pink","violet"];
  let SAVE_TIMER = null;

  function uid(){ return 'n'+Math.random().toString(36).slice(2,9); }
  function setStatus(msg){ $('#mp-status').text(msg); }

  function nodeTemplate(text = '', notes = '', color = 'blue'){
    return { id: uid(), text, notes, color, _collapsed: false, children: [] };
  }
  function ensureIds(node){
    if(!node) return nodeTemplate('Root Idea');
    if(!node.id) node.id = uid();
    (node.children||[]).forEach(ensureIds);
    return node;
  }

  // locate parent/index for an id
  function findParent(root, id){
    if(!root) return null;
    const kids = root.children || [];
    const idx = kids.findIndex(ch => ch.id === id);
    if(idx >= 0) return { parent: root, index: idx };
    for(const ch of kids){ const r = findParent(ch, id); if(r) return r; }
    return null;
  }
  function moveUp(root, id){ const r=findParent(root,id); if(!r) return; if(r.index>0){ const a=r.parent.children; [a[r.index-1],a[r.index]]=[a[r.index],a[r.index-1]]; } }
  function moveDown(root, id){ const r=findParent(root,id); if(!r) return; const a=r.parent.children; if(r.index<a.length-1){ [a[r.index+1],a[r.index]]=[a[r.index],a[r.index+1]]; } }
  function indent(root,id){ const r=findParent(root,id); if(!r) return; if(r.index===0) return; const a=r.parent.children; const prev=a[r.index-1]; const [m]=a.splice(r.index,1); prev.children=prev.children||[]; prev.children.push(m); }
  function outdent(root,id){ const r=findParent(root,id); if(!r) return; const grand=findParent(window.__mp_root,r.parent.id); if(!grand) return; const [m]=r.parent.children.splice(r.index,1); const s=grand.parent.children; const pIndex=s.findIndex(n=>n.id===r.parent.id); s.splice(pIndex+1,0,m); }

  function prune(node){
    if(!node || node._deleted) return null;
    const kids=(node.children||[]).map(prune).filter(Boolean);
    return { id: node.id, text: node.text||'', notes: node.notes||'', color: node.color||'blue', _collapsed: !!node._collapsed, children: kids };
  }

  // AUTOSAVE (debounced)
  function autosave(){
    const postId = MindPress.postId || 0;
    if(!postId) return; // cannot save without an ID
    clearTimeout(SAVE_TIMER);
    setStatus(MindPress.i18n.saving || 'Saving…');
    SAVE_TIMER = setTimeout(function(){
      const data = JSON.stringify(prune(window.__mp_root));
      $('#_mp_tree').val(data);
      $.post(MindPress.ajax, { action:'mp_save_tree', nonce: MindPress.nonce, post_id: postId, tree: data })
        .done(()=> setStatus(MindPress.i18n.saved || 'Saved ✓'))
        .fail(()=> setStatus('Save failed'));
    }, 350);
  }

  function saveHidden(){
    if(!window.__mp_root) return;
    const pruned = prune(window.__mp_root);
    $('#_mp_tree').val(JSON.stringify(pruned));
    autosave();
  }

  function colorDots(node, $li){
    const $wrap = $('<div class="mp-colors" aria-label="Colors"></div>');
    COLORS.forEach(c=>{
      const $d=$('<span class="mp-dot" role="button"></span>').attr('data-color',c).attr('title',c);
      if(node.color===c) $d.css('outline','2px solid #00000033');
      $d.on('click', ()=>{ node.color=c; $li.removeClass((i,cls)=>(cls.match(/(^|\\s)color-\\S+/g)||[]).join(' ')); $li.addClass('color-'+c); saveHidden(); });
      $wrap.append($d);
    });
    return $wrap;
  }

  function renderNode(node, level){
    const $li=$('<li class="mp-node"></li>').addClass('color-'+(node.color||'blue'));
    if(node._collapsed) $li.addClass('mp-collapsed');

    const $head=$('<div class="mp-head"></div>');
    const $handle=$('<span class="mp-handle" title="Collapse/Expand">▸</span>').on('click',()=>{ node._collapsed=!node._collapsed; $li.toggleClass('mp-collapsed'); saveHidden(); });

    const $titleWrap=$('<div class="mp-title"></div>');
    const $text=$('<input type="text" class="mp-text" placeholder="Heading / idea">').val(node.text||'');
    const $notes=$('<textarea class="mp-notes" placeholder="Notes (optional)"></textarea>').val(node.notes||'');
    $text.on('input', ()=>{ node.text=$text.val(); saveHidden(); });
    $notes.on('input', ()=>{ node.notes=$notes.val(); saveHidden(); });
    $titleWrap.append($text,$notes);

    const $actions=$('<div class="mp-actions-row"></div>');
    const $addChild=$('<button type="button" class="mp-btn">+ child</button>').on('click',()=>{ node.children=node.children||[]; node.children.push(nodeTemplate()); renderTree(); saveHidden(); });
    const $addSibling=$('<button type="button" class="mp-btn">+ sibling</button>').on('click',()=>{ const r=findParent(window.__mp_root,node.id); if(r){ r.parent.children.splice(r.index+1,0,nodeTemplate()); renderTree(); saveHidden(); }});
    const $up=$('<button type="button" class="mp-btn">↑</button>').on('click',()=>{ moveUp(window.__mp_root,node.id); renderTree(); saveHidden(); });
    const $down=$('<button type="button" class="mp-btn">↓</button>').on('click',()=>{ moveDown(window.__mp_root,node.id); renderTree(); saveHidden(); });
    const $indent=$('<button type="button" class="mp-btn">↳</button>').attr('title','Indent').on('click',()=>{ indent(window.__mp_root,node.id); renderTree(); saveHidden(); });
    const $outdent=$('<button type="button" class="mp-btn">↰</button>').attr('title','Outdent').on('click',()=>{ outdent(window.__mp_root,node.id); renderTree(); saveHidden(); });
    const $del=$('<button type="button" class="mp-btn" style="color:#b91c1c;">✕</button>').on('click',()=>{ if(confirm('Delete this node?')){ const r=findParent(window.__mp_root,node.id); if(r){ r.parent.children.splice(r.index,1); renderTree(); saveHidden(); } }});
    $actions.append($addChild,$addSibling,$up,$down,$indent,$outdent,$del,colorDots(node,$li));

    $head.append($handle,$titleWrap,$actions);
    $li.append($head);

    const $ul=$('<ul class="mp-children"></ul>');
    (node.children||[]).forEach(ch=>$ul.append(renderNode(ch,level+1)));
    $li.append($ul);
    return $li;
  }

  function renderTree(){
    const $app=$('#mp-app'); $app.empty();
    const $tree=$('<ul class="mp-tree"></ul>');
    $tree.append(renderNode(window.__mp_root,0));
    $app.append($tree);
  }

  function expandAll(n){ n._collapsed=false; (n.children||[]).forEach(expandAll); }
  function collapseAll(n){ n._collapsed=true; (n.children||[]).forEach(collapseAll); }

  function highlightMatches(q, node, $el){
    const hit=(node.text||'').toLowerCase().includes(q)||(node.notes||'').toLowerCase().includes(q);
    if(hit) $el.find('> .mp-head .mp-text').addClass('mp-highlight');
    (node.children||[]).forEach((ch,i)=>highlightMatches(q,ch,$el.find('> .mp-children > .mp-node').eq(i)));
  }

  // export/import helpers
  function download(filename, text){
    const blob=new Blob([text],{type:'application/json'});
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=filename; a.click(); URL.revokeObjectURL(a.href);
  }

  // ---- boot ----
  $(function(){
    const $app=$('#mp-app'); if(!$app.length) return;
    const postId = MindPress.postId || 0;

    // Try to load from the data attribute
    let initialStr = $app.data('json');
    let initialObj = null;
    if(initialStr){
      try{ initialObj = JSON.parse(initialStr); }catch(e){ console.warn('MindPress: bad JSON in meta, using server fetch fallback', e); }
    }

    // If nothing in the box but we have a postId, fetch from server (fallback)
    const renderFrom = (obj)=>{
      window.__mp_root = ensureIds(obj || nodeTemplate('Root Idea'));
      renderTree(); saveHidden();
    };

    if(!initialObj && postId){
      $.get(MindPress.ajax, { action:'mp_get_tree', nonce: MindPress.nonce, post_id: postId })
        .done(res=>{
          if(res && res.success && res.data && res.data.tree){
            try{
              renderFrom(JSON.parse(res.data.tree));
              return;
            }catch(e){ /* fall through to default */ }
          }
          renderFrom(initialObj);
        })
        .fail(()=> renderFrom(initialObj));
    } else {
      renderFrom(initialObj);
    }

    // toolbar
    $('#mp-expand-all').on('click',()=>{ expandAll(window.__mp_root); renderTree(); saveHidden(); });
    $('#mp-collapse-all').on('click',()=>{ collapseAll(window.__mp_root); renderTree(); saveHidden(); });
    $('#mp-export').on('click',()=>{ const data=$('#_mp_tree').val()||JSON.stringify(prune(window.__mp_root)); download('mindpress-map.json',data); setStatus(MindPress.i18n.exported||'Exported'); });
    $('#mp-import').on('click',()=>{ const t=window.prompt('Paste JSON here:'); if(!t) return; try{ const obj=ensureIds(JSON.parse(t)); window.__mp_root=obj; renderTree(); saveHidden(); }catch(e){ alert(MindPress.i18n.importErr||'Invalid JSON'); }});
    $('#mp-search').on('input',function(){ const q=$(this).val().toLowerCase(); renderTree(); if(q){ highlightMatches(q, window.__mp_root, $('#mp-app > .mp-tree > .mp-node').eq(0)); }});

    // actions
    $('#mp-generate').on('click', function(){
      const pruned=prune(window.__mp_root);
      const title=$('#title').val()||$('input[name="post_title"]').val()||'MindPress Draft';
      $.post(MindPress.ajax,{ action:'mp_generate_post', nonce:MindPress.nonce, tree:JSON.stringify(pruned), title })
        .done(res=>{ if(res&&res.success){ window.location.href=res.data.edit_link; } else { alert('Failed: '+(res&&res.data&&res.data.message?res.data.message:'Unknown error')); }})
        .fail(()=>alert('Request failed'));
    });

    $('#mp-insert').on('click', function(){
      const pid=postId; if(!pid){ alert('No current post ID. Save the post first.'); return; }
      const pruned=prune(window.__mp_root);
      $.post(MindPress.ajax,{ action:'mp_insert_into', nonce:MindPress.nonce, post_id:pid, tree:JSON.stringify(pruned) })
        .done(res=>{ if(res&&res.success){ alert('Inserted! Reloading…'); window.location.href=res.data.edit_link; } else { alert('Failed: '+(res&&res.data&&res.data.message?res.data.message:'Unknown error')); }})
        .fail(()=>alert('Request failed'));
    });
  });
})(jQuery);
