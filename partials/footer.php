</div>
</main>
</div>
<script>
(() => {
	const app = document.querySelector('[data-app-shell]');
	const toggle = document.querySelector('[data-toggle-navigation]');
	const closeButton = document.querySelector('[data-close-navigation]');
	const sidebar = document.getElementById('primary-navigation');
	if (!app || !toggle || !closeButton || !sidebar) return;

	const closeNavigation = (returnFocus = false) => {
		app.classList.remove('nav-open');
		document.body.classList.remove('nav-open');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-label', 'Open navigation');
		if (returnFocus) toggle.focus();
	};

	toggle.addEventListener('click', () => {
		const isOpen = app.classList.toggle('nav-open');
		document.body.classList.toggle('nav-open', isOpen);
		toggle.setAttribute('aria-expanded', String(isOpen));
		toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
		if (isOpen) sidebar.querySelector('nav a')?.focus();
	});

	closeButton.addEventListener('click', () => closeNavigation(true));
	sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeNavigation()));
	document.addEventListener('keydown', event => {
		if (event.key === 'Escape' && app.classList.contains('nav-open')) closeNavigation(true);
	});
	window.addEventListener('resize', () => {
		if (window.innerWidth > 950) closeNavigation();
	});
})();
</script>
</body>
</html>
