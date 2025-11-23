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
			// Colors
			const colors = analysis.colors || [];
			const colorBox = document.createElement('div');
			colorBox.innerHTML = '<h3>Colors</h3>';
			const list = document.createElement('div');
			list.style.display='flex';list.style.flexWrap='wrap';list.style.gap='8px';
			colors.forEach(function(c){
				const sw = document.createElement('div');
				sw.style.width='48px';sw.style.height='24px';sw.style.border='1px solid #ccc';sw.title=c;sw.style.background=c;
				list.appendChild(sw);
			});
			colorBox.appendChild(list);
			wrap.appendChild(colorBox);
			// Fonts
			const fonts = analysis.fonts || [];
			const fontBox = document.createElement('div');
			fontBox.innerHTML = '<h3>Fonts</h3>';
			const ul = document.createElement('ul');
			fonts.forEach(function(f){
				const li = document.createElement('li'); li.textContent = f; ul.appendChild(li);
			});
			fontBox.appendChild(ul);
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
				const res = await fetch(ctsEkg.endpoint, {
					method: 'POST',
					headers: { 'Content-Type':'application/json','X-WP-Nonce': ctsEkg.nonce },
					credentials: 'same-origin',
					body: JSON.stringify({ url: input.value, apply: true })
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
