import test from 'node:test';
import assert from 'node:assert/strict';
import {createAccountMenu} from '../public/lib/account-menu.mjs';

function fixture() {
  const elements = new Map(), events = new Map();
  const doc = {
    activeElement:null,
    getElementById(id) {
      if (!elements.has(id)) elements.set(id, {
        hidden:true, disabled:false, textContent:'', attributes:new Map(), listeners:new Map(),
        setAttribute(key,value) {this.attributes.set(key,value);},
        addEventListener(type,callback) {this.listeners.set(type,callback);},
        focus() {doc.activeElement = this;},
        contains(target) {return [...elements.values()].includes(target);},
        emit(type,values = {}) {return this.listeners.get(type)?.({target:this,preventDefault(){},...values});},
      });
      return elements.get(id);
    },
    addEventListener(type,callback) {events.set(type,callback);},
    emit(type,values = {}) {events.get(type)?.({preventDefault(){},...values});},
  };
  const menu = createAccountMenu(doc);
  return {doc,menu,get:id=>doc.getElementById(id)};
}

test('account disclosure supports keyboard opening, Escape and ordinary focus leaving', () => {
  const {doc,menu,get} = fixture();
  menu.show({displayName:'Fictional Example',email:'fictional@example.test'});
  assert.equal(get('account-name').textContent,'Fictional Example');
  assert.equal(get('account-email').textContent,'fictional@example.test');
  assert.equal(get('account-initial').textContent,'F');
  get('account-button').focus();
  get('account-button').emit('keydown',{key:'ArrowDown'});
  assert.equal(get('account-menu').hidden,false);
  assert.equal(doc.activeElement,get('sign-out'));
  assert.equal(get('account-button').attributes.get('aria-expanded'),'true');
  doc.emit('keydown',{key:'Escape'});
  assert.equal(get('account-menu').hidden,true);
  assert.equal(doc.activeElement,get('account-button'));
  get('account-button').emit('click');
  doc.emit('focusin',{target:get('sign-out')});
  assert.equal(get('account-menu').hidden,false);
  const nextControl = {}; doc.activeElement = nextControl;
  doc.emit('focusin',{target:nextControl});
  assert.equal(get('account-menu').hidden,true);
  assert.equal(doc.activeElement,nextControl);
});

test('outside clicks close the disclosure; busy and signed-out state cannot expose identity', () => {
  const {doc,menu,get} = fixture();
  menu.show({displayName:'Fictional Example',email:'fictional@example.test'});
  get('account-button').emit('click');
  doc.emit('pointerdown',{target:{}});
  assert.equal(get('account-menu').hidden,true);
  get('account-button').emit('click');
  menu.setBusy(true);
  get('account-button').emit('keydown',{key:'ArrowDown'});
  assert.equal(get('account-menu').hidden,true);
  assert.equal(get('sign-out').disabled,true);
  menu.setBusy(false);
  menu.show({email:'second@example.test'});
  assert.equal(get('account-name').textContent,'Your account');
  assert.equal(get('account-initial').textContent,'S');
  get('account-button').emit('click');
  get('sign-out').emit('click');
  assert.equal(get('account-menu').hidden,true);
  assert.equal(doc.activeElement,get('account-button'));
  menu.hide();
  for (const id of ['account-name','account-email','account-initial']) assert.equal(get(id).textContent,'');
  get('account-button').emit('click');
  assert.equal(get('account-menu').hidden,true);
  assert.equal(get('account-controls').hidden,true);
  assert.equal(get('account-button').attributes.get('aria-label'),'Account');
});

test('Data status appears only for exact owner capability and is cleared across accounts',()=>{
  const {doc,menu,get}=fixture();
  menu.show({email:'owner@example.test',canViewStatus:true});
  assert.equal(get('account-status').hidden,false);
  get('account-button').emit('keydown',{key:'ArrowDown'});
  assert.equal(doc.activeElement,get('account-status'));
  get('account-status').emit('click');assert.equal(get('account-menu').hidden,true);
  for(const canViewStatus of [false,undefined,'true',1]){
    menu.show({email:'other@example.test',canViewStatus});
    assert.equal(get('account-status').hidden,true);
    get('account-button').emit('keydown',{key:'ArrowDown'});assert.equal(doc.activeElement,get('sign-out'));
  }
  menu.show({email:'owner@example.test',canViewStatus:true});menu.hide();
  assert.equal(get('account-status').hidden,true);
});
