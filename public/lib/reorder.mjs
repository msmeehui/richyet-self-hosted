/** Move one item to its final zero-based position without mutating the input. */
export function moveItem(items, id, destination) {
  const source = items.findIndex(item => item.id === id);
  if (source < 0 || !Number.isInteger(destination)) return items;
  const target = Math.max(0, Math.min(items.length - 1, destination));
  if (source === target) return items;
  const next = items.slice();
  const [item] = next.splice(source, 1);
  next.splice(target, 0, item);
  return next;
}

/** Other row centres define insertion boundaries, including unequal row heights. */
export function pointerDestination(rows, draggedId, clientY) {
  return rows.filter(row => row.id !== draggedId && clientY >= (row.top + row.bottom) / 2).length;
}
