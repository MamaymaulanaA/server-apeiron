// Main JavaScript for License Server

// Confirm delete
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
    
    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Copy to clipboard
    const copyButtons = document.querySelectorAll('[data-copy]');
    copyButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const text = this.getAttribute('data-copy');
            navigator.clipboard.writeText(text).then(() => {
                const original = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    this.innerHTML = original;
                }, 2000);
            });
        });
    });
    
    // Modal handling
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                modal.classList.remove('show');
            });
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const required = form.querySelectorAll('[required]');
            let valid = true;
            
            required.forEach(input => {
                if (!input.value.trim()) {
                    valid = false;
                    input.style.borderColor = '#dc3232';
                } else {
                    input.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields');
            }
        });
    });
    
    // Dropdown menu for mobile (click to toggle)
    const navDropdowns = document.querySelectorAll('.nav-dropdown');
    navDropdowns.forEach(dropdown => {
        const link = dropdown.querySelector('.nav-link');
        const content = dropdown.querySelector('.nav-dropdown-content');
        
        if (link && content) {
            // Mobile: click to toggle
            if (window.innerWidth <= 768) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Close other dropdowns
                    navDropdowns.forEach(other => {
                        if (other !== dropdown) {
                            other.querySelector('.nav-dropdown-content').style.display = 'none';
                        }
                    });
                    // Toggle current dropdown
                    const isOpen = content.style.display === 'block';
                    content.style.display = isOpen ? 'none' : 'block';
                });
            }
        }
    });
    
    // Close dropdown when clicking outside (mobile)
    if (window.innerWidth <= 768) {
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.nav-dropdown')) {
                document.querySelectorAll('.nav-dropdown-content').forEach(content => {
                    content.style.display = 'none';
                });
            }
        });
    }
});

// Show modal
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

// Hide modal
function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

