{{-- budget-link: icon dẫn sang phần mềm Ngân sách & Chi phí (cổng 8080). Gỡ bằng: sudo bash snipeit-budget-link.sh --remove --}}
@verbatim
<script>
(function () {
  try {
    var url = 'http://' + location.hostname + ':8080/', title = 'Ngân sách & Chi phí';
    if (document.getElementById('budget-link-li')) return;
    var nav = document.querySelector('ul.navbar-nav') || document.querySelector('.navbar-custom-menu ul.nav') || document.querySelector('.navbar-custom-menu ul');
    var users = document.querySelector('.navbar a[href$="/users"], nav a[href$="/users"], header a[href$="/users"]');
    var ref = users ? users.closest('li') : null;
    if (ref && ref.parentNode) nav = ref.parentNode;
    if (!nav) return;
    var li = ref ? ref.cloneNode(false) : document.createElement('li');
    li.className = (li.className || '').replace(/\bactive\b/g, '').trim();
    if (!ref) li.className = 'nav-item';
    li.id = 'budget-link-li';
    var a = document.createElement('a');
    a.href = url; a.title = title; a.setAttribute('tabindex', '-1');
    if (users && users.className) a.className = users.className.replace(/\bactive\b/g, '').trim();
    else a.className = 'nav-link';
    a.innerHTML = '<i class="fas fa-coins fa-fw" aria-hidden="true"></i><span class="sr-only"></span>';
    a.lastChild.textContent = title;
    li.appendChild(a);
    if (ref && ref.parentNode === nav) nav.insertBefore(li, ref.nextSibling); else nav.appendChild(li);
  } catch (e) { /* lỗi cũng không ảnh hưởng Snipe-IT */ }
})();
</script>
@endverbatim
