const searchInput=document.querySelector('[data-stock-search]');
const statusFilter=document.querySelector('[data-status-filter]');
const rows=[...document.querySelectorAll('[data-stock-rows] tr')];
const emptyState=document.querySelector('[data-empty-state]');
function filterStock(){const query=(searchInput?.value||'').trim().toLowerCase();const status=statusFilter?.value||'all';let visible=0;rows.forEach(row=>{const matchesText=!query||row.dataset.search.includes(query);const matchesStatus=status==='all'||row.dataset.status===status;row.hidden=!(matchesText&&matchesStatus);if(!row.hidden)visible++});if(emptyState)emptyState.hidden=visible!==0}
searchInput?.addEventListener('input',filterStock);statusFilter?.addEventListener('change',filterStock);
document.querySelectorAll('[data-demo-form]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();const toast=document.querySelector('[data-demo-toast]');toast.hidden=false;form.reset();window.setTimeout(()=>toast.hidden=true,2200)}));