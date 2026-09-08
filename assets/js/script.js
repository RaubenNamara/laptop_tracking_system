// Wait until DOM is loaded
document.addEventListener("DOMContentLoaded", function() {

    // Sidebar toggle functionality (optional)
    const sidebar = document.querySelector('.sidebar');
    const toggleButton = document.querySelector('.sidebar-toggle');

    if(toggleButton){
        toggleButton.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }

    // Basic form validation for add forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;

            requiredFields.forEach(field => {
                if(!field.value.trim()){
                    valid = false;
                    field.style.border = '1px solid red';
                } else {
                    field.style.border = '';
                }
            });

            if(!valid){
                e.preventDefault();
                alert('Please fill all required fields.');
            }
        });
    });

    // Optional: auto-hide alert messages after 5s
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.display = 'none';
        }, 5000);
    });

});
