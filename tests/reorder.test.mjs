import test from 'node:test';
import assert from 'node:assert/strict';
import { moveItem, pointerDestination } from '../public/lib/reorder.mjs';

test('moves to a final position in either direction without changing item data or the original order', () => {
  const original = Object.freeze(['a','b','c','d'].map(id=>Object.freeze({id,quantity:'12.5'})));
  const moved = moveItem(original,'b',3);
  assert.deepEqual(moved.map(item=>item.id),['a','c','d','b']);
  assert.deepEqual(original.map(item=>item.id),['a','b','c','d']);
  assert.equal(moved[3],original[1]);
  assert.deepEqual(moveItem(moved,'b',0).map(item=>item.id),['b','a','c','d']);
});

test('keyboard endpoint moves clamp safely and invalid requests do not lose or duplicate holdings', () => {
  const items = ['a','b','c'].map(id=>({id}));
  assert.equal(moveItem(items,'a',-1),items);
  assert.equal(moveItem(items,'c',3),items);
  assert.equal(moveItem(items,'missing',1),items);
  assert.equal(moveItem(items,'b',NaN),items);
  assert.deepEqual(moveItem(items,'b',-10).map(item=>item.id),['b','a','c']);
  assert.deepEqual(moveItem(items,'b',99).map(item=>item.id),['a','c','b']);
});

test('pointer insertion uses row centres, skips the dragged row, and supports different row heights', () => {
  const rows = [{id:'a',top:20,bottom:100},{id:'b',top:100,bottom:240},{id:'c',top:240,bottom:340}];
  assert.equal(pointerDestination(rows,'b',0),0);
  assert.equal(pointerDestination(rows,'b',59),0);
  assert.equal(pointerDestination(rows,'b',60),1);
  assert.equal(pointerDestination(rows,'b',170),1);
  assert.equal(pointerDestination(rows,'b',290),2);
  assert.equal(pointerDestination(rows,'b',1000),2);
  assert.equal(pointerDestination(rows.map(row=>({...row,top:row.top-150,bottom:row.bottom-150})),'b',140),2);
});
