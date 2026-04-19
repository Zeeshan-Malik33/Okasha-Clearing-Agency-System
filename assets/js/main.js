document.addEventListener('submit', function (e) {
    const form = e.target;

    if (form.dataset.ajax !== 'true') return;

    e.preventDefault();

    const target = form.dataset.target;
    const loader = document.getElementById(target);

    const formData = new FormData(form);

    loader.classList.add('opacity-50');

    fetch(form.action, {
        method: form.method || 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(html => {
        loader.innerHTML = html;
        loader.classList.remove('opacity-50');
    })
    .catch(() => {
        loader.classList.remove('opacity-50');
        alert('Something went wrong');
    });
});

function getExpenseModal() {
    return document.getElementById('expenseModal');
}

function openExpenseModal() {
    const modal = getExpenseModal();
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeExpenseModal() {
    const modal = getExpenseModal();
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

document.addEventListener('click', function (e) {
    const openBtn = e.target.closest('#openExpenseModal');
    if (openBtn) {
        e.preventDefault();
        openExpenseModal();
        return;
    }

    const closeBtn = e.target.closest('#closeExpenseModal, #cancelExpenseModal');
    if (closeBtn) {
        e.preventDefault();
        closeExpenseModal();
        return;
    }

    const modal = getExpenseModal();
    if (modal && e.target === modal) {
        closeExpenseModal();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeExpenseModal();
        // Also close container modal if open
        const containerModal = document.getElementById('containerModal');
        if (containerModal && !containerModal.classList.contains('hidden')) {
            closeContainerModal();
        }
    }
});

// Update sidebar active state
function updateSidebarActive(page) {
    const sidebarLinks = document.querySelectorAll('.sidebar-link');
    sidebarLinks.forEach(link => {
        const linkPage = link.getAttribute('data-page');
        if (linkPage === page) {
            // Make this link active
            link.classList.remove('hover:bg-gray-700', 'ajax-link');
            link.classList.add('bg-gradient-to-r', 'from-blue-600', 'to-blue-500', 'border-l-4', 'border-blue-300');
        } else {
            // Make this link inactive
            link.classList.remove('bg-gradient-to-r', 'from-blue-600', 'to-blue-500', 'border-l-4', 'border-blue-300');
            link.classList.add('hover:bg-gray-700', 'ajax-link');
        }
    });
}

// Container Modal Functions
window.openContainerModal = function(isEdit) {
    console.log('openContainerModal called, isEdit:', isEdit);
    const modal = document.getElementById('containerModal');
    console.log('Modal element:', modal);
    
    if (!modal) {
        console.error('Container modal not found in DOM');
        alert('Error: Modal not found. Please refresh the page.');
        return;
    }
    
    // Show modal
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    
    if (!isEdit) {
        const modalTitle = document.getElementById('modalTitle');
        const containerForm = document.getElementById('containerForm');
        const containerId = document.getElementById('container_id');
        
        if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-box mr-2"></i>Add Container';
        if (containerForm) {
            containerForm.reset();
            containerForm.action = 'containers.php?action=add';
        }
        if (containerId) containerId.value = '';
    }
    
    console.log('Modal should now be visible');
};

window.closeContainerModal = function() {
    const modal = document.getElementById('containerModal');
    const containerForm = document.getElementById('containerForm');
    
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }
    if (containerForm) containerForm.reset();
};

// Handle all container modal related clicks
document.addEventListener('click', function(e) {
    // Check if clicked element is the add container button (by ID or onclick attribute)
    const addButton = e.target.closest('#addContainerBtn') || e.target.closest('button[onclick*="openContainerModal"]');
    if (addButton) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Add Container button clicked');
        openContainerModal();
        return;
    }
    
    // Check if clicking outside modal to close it
    const modal = document.getElementById('containerModal');
    if (modal && e.target === modal) {
        console.log('Clicked outside modal, closing');
        closeContainerModal();
    }
});

// AJAX navigation for sidebar links
document.addEventListener('click', function(e) {
    const link = e.target.closest('a[data-page]');
    if (!link) return;
    
    // Only handle internal page links
    const page = link.dataset.page;
    if (!page) return;
    
    e.preventDefault();
    
    const pageContent = document.getElementById('page-content');
    if (!pageContent) {
        window.location.href = page;
        return;
    }
    
    pageContent.style.opacity = '0.5';
    
    fetch(page, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.html) {
            pageContent.outerHTML = data.html;
            
            // Update sidebar highlighting
            updateSidebarActive(page);
            
            // Re-initialize form handlers for the new content
            setTimeout(initializeFormHandlers, 100);
            
            // Re-execute any scripts in the new content
            const scripts = document.querySelectorAll('#page-content script');
            scripts.forEach(script => {
                const newScript = document.createElement('script');
                if (script.src) {
                    newScript.src = script.src;
                } else {
                    newScript.textContent = script.textContent;
                }
                script.parentNode.replaceChild(newScript, script);
            });
            
            // Reset opacity for the new content
            const newPageContent = document.getElementById('page-content');
            if (newPageContent) {
                newPageContent.style.opacity = '1';
            }
        } else {
            window.location.href = page;
        }
    })
    .catch(err => {
        console.error('Navigation error:', err);
        window.location.href = page;
    });
});

// Customer form AJAX submission
document.addEventListener('DOMContentLoaded', function() {
    const modal = getExpenseModal();
    if (modal && modal.dataset.open === 'true') {
        openExpenseModal();
    }

    initializeFormHandlers();
});

// Initialize form handlers (also called after AJAX content loads)
function initializeFormHandlers() {
    const customerForm = document.getElementById('customerForm');
    
    if (customerForm && !customerForm.dataset.initialized) {
        customerForm.dataset.initialized = 'true';
        customerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update the customer table
                    const tableBody = document.getElementById('customerTableBody');
                    if (tableBody) {
                        tableBody.innerHTML = data.html;
                    }
                    
                    // Redirect to customer list view
                    window.location.href = 'customers.php';
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Failed to add customer');
            });
        });
    }

    // Container form AJAX submission (handles both add and edit)
    const containerForm = document.getElementById('containerForm');
    
    if (containerForm && !containerForm.dataset.initialized) {
        containerForm.dataset.initialized = 'true';
        containerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const url = this.action || window.location.href;
            
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.html) {
                    // Update the page content
                    const pageContent = document.getElementById('page-content');
                    if (pageContent) {
                        pageContent.outerHTML = data.html;
                        // Re-initialize handlers for new content
                        setTimeout(initializeFormHandlers, 100);
                    }
                } else if (data.success) {
                    closeContainerModal();
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Failed to submit container form');
            });
        });
    }

    // Container expense form AJAX submission
    const containerExpenseForm = document.getElementById('containerExpenseForm');
    
    if (containerExpenseForm) {
        containerExpenseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Update the page content
                    const pageContent = document.getElementById('page-content');
                    if (pageContent) {
                        pageContent.outerHTML = data.html;
                        
                        // Re-attach event listener to the new form
                        setTimeout(() => {
                            const newExpenseForm = document.getElementById('containerExpenseForm');
                            if (newExpenseForm) {
                                newExpenseForm.addEventListener('submit', arguments.callee);
                            }
                        }, 100);
                    }
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Failed to add expense');
            });
        });
    }
});

// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const collapseIcon = document.getElementById('collapseIcon');
    const collapseText = document.getElementById('collapseText');
    
    if (!sidebar) return;

    // On mobile, collapse button should close the sidebar overlay
    if (window.innerWidth < 768) {
        const overlay = document.getElementById('mobileSidebarOverlay');
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('open');
        document.body.classList.remove('mobile-sidebar-open');
        return;
    }
    
    sidebar.classList.toggle('sidebar-collapsed');
    
    if (sidebar.classList.contains('sidebar-collapsed')) {
        // Collapsed state
        collapseIcon.textContent = 'keyboard_double_arrow_right';
        if (collapseText) collapseText.textContent = 'Expand';
        localStorage.setItem('sidebarCollapsed', 'true');
    } else {
        // Expanded state
        collapseIcon.textContent = 'keyboard_double_arrow_left';
        if (collapseText) collapseText.textContent = 'Collapse';
        localStorage.setItem('sidebarCollapsed', 'false');
    }
}
