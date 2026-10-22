// Display-only allocation: consume the same full-portfolio valuations as the
// overview. Never turn the known part of an incomplete portfolio into 100%.
const colours = ['#527da1','#9680b0','#c6a45d','#657291','#b58dac','#7595a6',
  '#98876c','#7189bd','#a78ec9','#627b87','#c4b785','#a7a4bd'];

export function portfolioBreakdown(items, total) {
  const colourIds = [...items].map(item=>String(item.id)).sort();
  const result = items.map((item,index)=>({...item,index,share:null,start:null,end:null,
    color:colours[colourIds.indexOf(String(item.id)) % colours.length]}));
  const available = value=>typeof value === 'number' && Number.isFinite(value) && value >= 0;
  result.sort((a,b)=> {
    if (!available(a.value)) return available(b.value) ? 1 : a.index-b.index;
    if (!available(b.value)) return -1;
    return b.value-a.value || a.index-b.index;
  });
  if (!items.length) return {status:'empty',items:result,total:null};
  const known = result.every(item=>available(item.value));
  const sum = known ? result.reduce((value,item)=>value+item.value,0) : null;
  const complete = total?.completeValue === true && available(total.value) && known && Number.isFinite(sum)
    && Math.abs(sum-total.value) <= Number.EPSILON * Math.max(sum,total.value) * Math.max(8,items.length*4);
  if (!complete) return {status:'unavailable',items:result,total:null};
  if (total.value === 0) return {status:'zero',items:result,total:0};
  let position=0;
  for (const item of result) {
    item.share=item.value/total.value*100;
    item.start=position;
    position+=item.value/total.value;
    item.end=Math.min(1,position);
  }
  const positive=result.filter(item=>item.share > 0);
  const groups=positive.length > 8 ? [...positive.slice(0,7).map(item=>[item]),positive.slice(7)] : positive.map(item=>[item]);
  let sliceStart=0;
  const slices=groups.map((group,index)=>{
    const share=group.reduce((sum,item)=>sum+item.share,0),color=group.length > 1 ? '#9ba6b6' : colours[index];
    const slice={id:group.length > 1 ? 'other' : 'holding-'+String(group[0].id),name:group.length > 1 ? 'Other investments' : group[0].name,
      holdingIds:group.map(item=>item.id),share,color,start:sliceStart,end:Math.min(1,sliceStart+share/100)};
    sliceStart=slice.end;
    for (const item of group) {item.sliceId=slice.id; item.color=color;}
    return slice;
  });
  return {status:'ready',items:result,slices,total:total.value};
}

export function donutPath(start,end) {
  if (!Number.isFinite(start) || !Number.isFinite(end) || start < 0 || end > 1 || end <= start) return '';
  const point=(fraction,radius)=>{
    const angle=fraction*Math.PI*2-Math.PI/2;
    return `${(120+radius*Math.cos(angle)).toFixed(6)} ${(120+radius*Math.sin(angle)).toFixed(6)}`;
  };
  if (end-start >= 1-Number.EPSILON*8) {
    return 'M 120 16 A 104 104 0 1 1 120 224 A 104 104 0 1 1 120 16 L 120 53 A 67 67 0 1 0 120 187 A 67 67 0 1 0 120 53 Z';
  }
  const large=end-start > .5 ? 1 : 0;
  return `M ${point(start,104)} A 104 104 0 ${large} 1 ${point(end,104)} L ${point(end,67)} A 67 67 0 ${large} 0 ${point(start,67)} Z`;
}
