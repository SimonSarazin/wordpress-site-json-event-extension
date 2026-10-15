document.addEventListener('click', function (e) {
	var t = e.target;
	var oa = t.closest('.tle-oa');
	if (oa) {
		e.preventDefault();
		var m = oa.parentNode.querySelector('.tle-oa-msg');
		var f = new FormData();
		f.append('action', 'tle_openagenda');
		f.append('_wpnonce', oa.getAttribute('data-nonce'));
		f.append('slug', oa.getAttribute('data-slug'));
		m.hidden = false; m.textContent = 'Envoi…';
		fetch(oa.getAttribute('data-url'), { method: 'POST', body: f, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) { m.textContent = res.data || (res.success ? 'Envoyé ✓' : 'Échec'); })
			.catch(function () { m.textContent = 'Échec de l’envoi'; });
		return;
	}
	var a = t.closest('[data-tle-copy]');
	if (a) {
		e.preventDefault();
		var n = a.parentNode.querySelector('.tle-copied');
		if (navigator.clipboard) {
			navigator.clipboard.writeText(a.getAttribute('data-tle-copy')).then(function () { if (n) { n.hidden = false; } });
		}
	}
	// Referme les autres fenêtres quand on clique ailleurs.
	document.querySelectorAll('.tle-pop[open]').forEach(function (d) {
		if (!d.contains(t)) { d.removeAttribute('open'); }
	});
});
