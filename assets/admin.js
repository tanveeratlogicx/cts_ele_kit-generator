(function(){
	function $(sel){return document.querySelector(sel);} 
	function esc(str){const div=document.createElement('div');div.textContent=str;return div.innerHTML;}

	document.addEventListener('DOMContentLoaded', function(){
		var form = $('#cts-ekg-form');
		if(!form) return;
		var btn = $('#cts-ekg-run');
		var input = $('#cts-ekg-url');
		var out = $('#cts-ekg-result');

		function renderPreview(data){
			out.innerHTML = '';
			if(!data || !data.analysis){ return; }
			const analysis = data.analysis;
			const preview = data.preview || {};
			const wrap = document.createElement('div');
			wrap.className = 'cts-ekg-preview';
			            // Global Custom Colors -> use preview.custom_colors with labels; do not expose raw analysis.colors
            const labeled = analysis.labeled_colors || {};
            const colorBox = document.createElement('div');
            colorBox.innerHTML = '<h3>Global Custom Colors</h3>';
            const list = document.createElement('div');
            list.style.display='flex';list.style.flexDirection='column';list.style.gap='6px';
            const custom = (preview && Array.isArray(preview.custom_colors)) ? preview.custom_colors : [];
            custom.forEach(function(item){
                const row = document.createElement('div');
                row.style.display='flex'; row.style.alignItems='center'; row.style.gap='10px';
                const label = document.createElement('div'); label.textContent = item.title || 'Global Custom Color'; label.style.minWidth='220px';
                const sw = document.createElement('div'); sw.style.width='48px'; sw.style.height='24px'; sw.style.border='1px solid #ccc'; sw.title=item.color; sw.style.background=item.color;
                const code = document.createElement('code'); code.textContent = item.color;
                row.appendChild(label); row.appendChild(sw); row.appendChild(code);
                list.appendChild(row);
            });
            colorBox.appendChild(list);
			// Labeled mapping
			if (labeled && Object.keys(labeled).length){
				const table = document.createElement('table');
				table.className = 'widefat striped';
				const tbody = document.createElement('tbody');
				['primary','secondary','text','accent'].forEach(function(key){
					const val = labeled[key];
					const tr = document.createElement('tr');
					const td1 = document.createElement('td'); td1.textContent = key.charAt(0).toUpperCase()+key.slice(1);
					const td2 = document.createElement('td');
					const inp = document.createElement('input'); inp.type='text'; inp.value = val || ''; inp.placeholder = '#RRGGBB or rgba(...)'; inp.dataset.role = 'color-'+key;
					td2.appendChild(inp);
					const td3 = document.createElement('td');
					if(val){ const sw = document.createElement('div'); sw.style.width='48px'; sw.style.height='24px'; sw.style.border='1px solid #ccc'; sw.style.background=val; td3.appendChild(sw);} else { td3.textContent=''; }
					tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				colorBox.appendChild(table);
			}
			wrap.appendChild(colorBox);
			// Fonts (Elementor-style mapping)
			const fonts = analysis.fonts || [];
			const fontBox = document.createElement('div');
			fontBox.innerHTML = '<h3>Fonts</h3>';
			const famWrap = document.createElement('div');
			famWrap.className = 'cts-ekg-font-families';
			const rows = [
				{ key: 'primary', label: 'Primary (Headings)', def: fonts[0] || '' },
				{ key: 'body', label: 'Body (Text)', def: fonts[0] || '' },
				{ key: 'secondary', label: 'Secondary', def: fonts[1] || '' }
			];
			rows.forEach(function(r){
				const row = document.createElement('div'); row.style.display='flex'; row.style.alignItems='center'; row.style.gap='8px'; row.style.marginBottom='6px';
				const lab = document.createElement('label'); lab.style.minWidth='160px'; lab.textContent=r.label;
				const inp = document.createElement('input'); inp.type='text'; inp.value=r.def; inp.placeholder='e.g., Inter'; inp.dataset.role='font-'+r.key;
				row.appendChild(lab); row.appendChild(inp);
				famWrap.appendChild(row);
			});
			fontBox.appendChild(famWrap);
			// Font sizes
			const sizes = analysis.font_sizes || {};
			if (sizes && Object.keys(sizes).length){
				const sizeTable = document.createElement('table');
				sizeTable.className = 'widefat striped';
				const t2 = document.createElement('tbody');
				['base','h1','h2','h3','h4','h5','h6','cta'].forEach(function(k){
					const tr = document.createElement('tr');
					const td1 = document.createElement('td'); td1.textContent = (k==='base'?'Body/Base':(k==='cta'?'CTA Buttons':k.toUpperCase()));
					const td2 = document.createElement('td');
					const inp = document.createElement('input'); inp.type = 'text'; inp.value = sizes[k] || ''; inp.placeholder = 'e.g., 16px or 1rem or clamp(...)'; inp.dataset.role = 'size-'+k;
					td2.appendChild(inp);
					tr.appendChild(td1); tr.appendChild(td2); t2.appendChild(tr);
				});
				sizeTable.appendChild(t2);
				const h = document.createElement('h4'); h.textContent = 'Font Sizes';
				fontBox.appendChild(h);
				fontBox.appendChild(sizeTable);
			}
			wrap.appendChild(fontBox);
			// Actions
			const actions = document.createElement('div');
			actions.style.marginTop='10px';
			const applyBtn = document.createElement('button');
			applyBtn.className='button button-primary';
			applyBtn.textContent='Apply to Active Kit';
			const regenBtn = document.createElement('button');
			regenBtn.className='button';regenBtn.style.marginLeft='8px';
			regenBtn.textContent='Regenerate';
			actions.appendChild(applyBtn); actions.appendChild(regenBtn);
			wrap.appendChild(actions);
			out.appendChild(wrap);

			applyBtn.addEventListener('click', function(){
				apply();
			});
			regenBtn.addEventListener('click', function(){
				run();
			});
		}

		function showNotice(type, msg){
			out.innerHTML = '<div class="notice notice-' + type + '"><p>' + esc(msg) + '</p></div>';
		}

		async function run(){
			if(!input.checkValidity()) { input.reportValidity(); return; }
			btn.disabled = true; btn.classList.add('updating-message'); out.innerHTML = '';
			try{
				const res = await fetch(ctsEkg.endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctsEkg.nonce
					},
					credentials: 'same-origin',
					body: JSON.stringify({ url: input.value, apply: false })
				});
				const data = await res.json();
				if(!data.ok){ showNotice('error', data.message || 'Failed'); return; }
				renderPreview(data);
			}catch(e){
				showNotice('error', e.message || String(e));
			}finally{
				btn.disabled = false; btn.classList.remove('updating-message');
			}
		}

		async function apply(){
			btn.disabled = true; btn.classList.add('updating-message');
			try{
				// collect overrides from inputs
				const overrides = { labeled_colors: {}, font_sizes: {}, font_families: {} };
				['primary','secondary','text','accent'].forEach(function(key){
					const el = out.querySelector('[data-role="color-'+key+'"]'); if(el && el.value){ overrides.labeled_colors[key]=el.value; }
				});
				['base','h1','h2','h3','h4','h5','h6','cta'].forEach(function(k){
					const el = out.querySelector('[data-role="size-'+k+'"]'); if(el && el.value){ overrides.font_sizes[k]=el.value; }
				});
				['primary','body','secondary'].forEach(function(k){
					const el = out.querySelector('[data-role="font-'+k+'"]'); if(el && el.value){ overrides.font_families[k]=el.value; }
				});
				const res = await fetch(ctsEkg.endpoint, {
					method: 'POST',
					headers: { 'Content-Type':'application/json','X-WP-Nonce': ctsEkg.nonce },
					credentials: 'same-origin',
					body: JSON.stringify({ url: input.value, apply: true, overrides })
				});
				const data = await res.json();
				if(data.ok){ showNotice('success', data.message || 'Kit applied.'); }
				else { showNotice('error', data.message || 'Failed to apply kit.'); }
			}catch(e){ showNotice('error', e.message || String(e)); }
			finally{ btn.disabled=false; btn.classList.remove('updating-message'); }
		}

		btn.addEventListener('click', run);
	});
})();
