// 🧩 the bot's own way of arranging a keyboard, on the web: tap a button and then
// another to swap the two, tap ＋ to join that row, «new row» for a row of its
// own - or drag with a mouse. Two to a row at most, as in the bot. The row
// numbers each button's form field sends (.kb-rowin) follow what is on screen.
document.querySelectorAll('.kb-grid').forEach(function (grid) {
  var sel = null, dragged = null;
  function unselect() { if (sel) sel.classList.remove('sel'); sel = null; }
  function swap(a, b) {
    var mark = document.createElement('span');
    a.parentNode.insertBefore(mark, a);
    b.parentNode.insertBefore(a, b);
    mark.parentNode.insertBefore(b, mark);
    mark.remove();
  }
  function normalize() {
    grid.querySelectorAll('.kb-row').forEach(function (row) {
      row.querySelectorAll('.kb-slot').forEach(function (s) { s.remove(); });
      var n = row.querySelectorAll('.kb-chip').length;
      if (!n) { row.remove(); return; }
      if (n < 2) { var slot = document.createElement('div'); slot.className = 'kb-slot'; slot.textContent = '＋'; row.appendChild(slot); }
    });
    grid.querySelectorAll('.kb-row').forEach(function (row, r) {
      row.querySelectorAll('.kb-rowin').forEach(function (inp) { inp.value = r + 1; });
    });
  }
  function place(chip, target) {
    var other = target.closest('.kb-chip'), slot = target.closest('.kb-slot'), fresh = target.closest('.kb-newrow');
    if (other && other !== chip) {
      swap(chip, other);
    } else if (slot) {
      slot.parentNode.insertBefore(chip, slot);
    } else if (fresh) {
      var row = document.createElement('div');
      row.className = 'kb-row';
      row.appendChild(chip);
      grid.insertBefore(row, fresh);
    } else {
      return;
    }
    normalize();
  }
  grid.addEventListener('click', function (e) {
    var chip = e.target.closest('.kb-chip');
    if (!sel) {
      if (chip) { sel = chip; chip.classList.add('sel'); }
      return;
    }
    if (chip === sel) { unselect(); return; }
    var picked = sel;
    unselect();
    place(picked, e.target);
  });
  grid.addEventListener('dragstart', function (e) {
    dragged = e.target.closest('.kb-chip');
    if (dragged) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); unselect(); }
  });
  grid.addEventListener('dragover', function (e) {
    if (dragged && (e.target.closest('.kb-chip') || e.target.closest('.kb-slot') || e.target.closest('.kb-newrow'))) e.preventDefault();
  });
  grid.addEventListener('drop', function (e) {
    e.preventDefault();
    if (dragged) place(dragged, e.target);
    dragged = null;
  });
  grid.addEventListener('dragend', function () { dragged = null; });
});
