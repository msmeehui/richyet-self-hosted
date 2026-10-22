// The account disclosure is shared by the portfolio and standalone pages.
export function createAccountMenu(doc = document) {
  const controls = doc.getElementById('account-controls');
  const button = doc.getElementById('account-button');
  const menu = doc.getElementById('account-menu');
  const signOut = doc.getElementById('sign-out');
  const status = doc.getElementById('account-status');
  const name = doc.getElementById('account-name');
  const email = doc.getElementById('account-email');
  const initial = doc.getElementById('account-initial');

  function close({restoreFocus = false} = {}) {
    menu.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus && !controls.hidden) button.focus();
  }
  function open({focusAction = false} = {}) {
    if (controls.hidden || button.disabled) return;
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    if (focusAction) (status && !status.hidden ? status : signOut).focus();
  }
  button.addEventListener('click', () => menu.hidden ? open() : close());
  button.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      open({focusAction:true});
    }
  });
  doc.addEventListener('pointerdown', event => {
    if (!menu.hidden && !controls.contains(event.target)) close();
  });
  doc.addEventListener('focusin', event => {
    if (!menu.hidden && !controls.contains(event.target)) close();
  });
  doc.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !menu.hidden) {
      event.preventDefault();
      close({restoreFocus:true});
    }
  });
  signOut.addEventListener('click', () => close({restoreFocus:true}));
  status?.addEventListener('click', () => close());

  return {
    close,
    show(user) {
      const displayName = typeof user.displayName === 'string' ? user.displayName.trim() : '';
      name.textContent = displayName || 'Your account';
      email.textContent = user.email;
      initial.textContent = Array.from(displayName || user.email.trim())[0]?.toLocaleUpperCase('en') || '?';
      button.setAttribute('aria-label', `Account: ${displayName || user.email}`);
      if (status) status.hidden = user.canViewStatus !== true;
      controls.hidden = false;
      close();
    },
    hide() {
      close();
      controls.hidden = true;
      name.textContent = '';
      email.textContent = '';
      initial.textContent = '';
      if (status) status.hidden = true;
      button.setAttribute('aria-label', 'Account');
    },
    setBusy(value) {
      if (value) close();
      button.disabled = value;
      signOut.disabled = value;
    },
  };
}
