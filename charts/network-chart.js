(function () {
  "use strict";
  jQuery(document).ready(function () {
    const obj = window.wp_js_object;

    jQuery('#metrics-sidemenu').foundation('down', jQuery(`#${obj.base_slug}-menu`));

    const chartDiv = jQuery('#chart');
    if (obj.types.length == 0) {
      chartDiv.empty().text(obj.translations.none);
      return;
    }

    chartDiv.empty().html(`
      <span class="section-header">${obj.translations.title}</span>
      <hr style="max-width:100%;">
      <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
        <select id="cn-post-type" style="max-width:250px;"></select>
        <select id="cn-p2p-key" style="max-width:300px;"></select>
        <span id="cn-spinner" class="loading-spinner" style="display:none;"></span>
      </div>
      <p id="cn-message"></p>
      <div id="cn-network" style="height:70vh; border:1px solid #ccc;"></div>
    `);

    const $postTypeSelect = jQuery('#cn-post-type');
    const $keySelect = jQuery('#cn-p2p-key');
    const $message = jQuery('#cn-message');
    const $spinner = jQuery('#cn-spinner');

    // Populate a <select> and set the selection to selected, if it exists
    function prepare_select($select, items, selected) {
      $select.empty();
      for (const i of items) {
        $select.append(jQuery('<option>').val(i.key).text(i.label))
      }
      const val = items.some(i => i.key === selected) ? selected : items[0].key;
      $select.val(val);
    }

    function getPostTypeKeys($postType) {
      const type = obj.types.find(t => t.key === $postType);
      return type.p2p_keys;
    }

    const nodes = new vis.DataSet();
    const edges = new vis.DataSet();
    const network = new vis.Network(
      document.getElementById('cn-network'),
      { nodes, edges },
      {
        nodes: { shape: 'dot', size: 10, font: { size: 14 } },
        edges: { smooth: false, color: { color: '#aaa' } },
        physics: { solver: 'forceAtlas2Based', stabilization: { iterations: 200 } },
        interaction: { hover: true }
      }
    );

    network.on('doubleClick', p => {
      if (p.nodes.length) {
        const node = nodes.get(p.nodes[0]);
        window.open(`${obj.home_url}${node.group}/${node.id}`, '_blank');
      }
    });

    let current_request = 0;
    function createGraph() {
      const this_request = (current_request += 1);
      $spinner.show();
      $message.text('');

      fetch(obj.rest_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': obj.nonce },
        body: JSON.stringify({ post_type: $postTypeSelect.val(), p2p_key: $keySelect.val() })
      })
        .then(r => (r.ok ? r.json() : Promise.reject(r)))
        .then(data => {
          if (this_request !== current_request) return;
          nodes.clear();
          edges.clear();
          nodes.add(data.nodes);
          edges.add(data.edges);
          network.once('stabilized', () => network.fit()); // refit the screen after stabilization
          if (!data.nodes.length) $message.text(obj.translations.empty);
        })
        .catch(() => { if (this_request === current_request) $message.text(obj.translations.error); })
        .finally(() => { if (this_request === current_request) $spinner.hide(); });
    }

    prepare_select($postTypeSelect, obj.types, null);
    prepare_select($keySelect, getPostTypeKeys($postTypeSelect.val()), $keySelect.val());

    $postTypeSelect.on('change', () => {
      prepare_select($keySelect, getPostTypeKeys($postTypeSelect.val()), $keySelect.val());
      createGraph();
    });

    $keySelect.on('change', createGraph);
    createGraph();
  });
})();
