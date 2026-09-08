document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('toggleBtn');
    const sidebar = document.getElementById('sidebar');

    toggleBtn.addEventListener('click', () => {
        if(window.innerWidth < 768){
            sidebar.classList.toggle('show'); // mobile: slide in/out
        } else {
            sidebar.classList.toggle('collapsed'); // desktop: collapse width
        }
    });
});
