const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../../public/js/personal-library.js`, 'utf8');
// Exercise the production storage boundary without a browser or new dependencies.
const factory = vm.runInNewContext(`(${source.slice(source.indexOf('    function backfillDraftStore('),source.indexOf('    async function browseHistory()')).trim()})`);
const title = (id,type='movie') => ({mediaType:type,tmdbId:id,title:`Title ${id}`,year:'2001',reaction:'Loved',existingNote:'Do not persist provider/personal snapshots'});
function setup() {let value=null; return {getItem:()=>value,setItem:(key,next)=>{value=next;}};}
test('a new visit restores mixed movie/TV selections and last position without viewing claims',()=>{
 const storage=setup(), first=factory(storage,'draft');
 first.update([title(1),title(1,'tv')],[],{mediaType:'tv',list:'popular',page:72});
 const restored=factory(storage,'draft').read();
 assert.equal(restored.items.length,2); assert.equal(restored.position.page,72);
 assert.equal(restored.items[1].reaction,'Loved');
 assert.equal(Object.hasOwn(restored.items[0],'existingNote'),false);
 assert.equal(Object.hasOwn(restored.items[0],'viewingStatus'),false);
});
test('tabs merge changes and successful saves remove only reviewed identities',()=>{
 const storage=setup(), a=factory(storage,'draft'), b=factory(storage,'draft');
 a.update([title(1)]); b.update([title(2)]); a.update([],['movie:1']);
 assert.equal(b.read().items.length,1); assert.equal(b.read().items[0].tmdbId,2);
});
test('corrupt or incompatible drafts cannot create selection records',()=>{
 const storage=setup(); storage.setItem('draft','broken'); assert.equal(factory(storage,'draft').read().items.length,0);
 storage.setItem('draft',JSON.stringify({version:1,items:[{...title(1),mediaType:'person'},title(-1)],position:{page:999}}));
 const result=factory(storage,'draft').read(); assert.equal(result.items.length,0); assert.equal(result.position,null);
});
test('the 100-title limit rejects another tab addition without discarding the saved draft',()=>{
 const storage=setup(), draft=factory(storage,'draft'); draft.update(Array.from({length:100},(_,i)=>title(i+1)));
 assert.throws(()=>draft.update([title(101)]), /100 titles/); assert.equal(draft.read().items.length,100);
});
test('storage failure surfaces instead of claiming an unsaved draft was persisted',()=>{
 const draft=factory({getItem:()=>null,setItem:()=>{throw Error('quota');}},'draft');
 assert.throws(()=>draft.update([title(1)]),/quota/);
});
