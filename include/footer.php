</main>
</div> <!-- End of flex container -->

<script>
// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const collapseIcon = document.getElementById('collapseIcon');
    const collapseText = document.getElementById('collapseText');

    if (!sidebar) return;

    // On mobile, close the sidebar overlay instead of collapsing
    if (window.innerWidth < 768) {
        const overlay = document.getElementById('mobileSidebarOverlay');
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('open');
        document.body.classList.remove('mobile-sidebar-open');
        return;
    }

    const isCollapsed = sidebar.classList.contains('sidebar-collapsed');

    if (isCollapsed) {
        // Expand
        sidebar.classList.remove('sidebar-collapsed');
        if (collapseIcon) collapseIcon.textContent = 'keyboard_double_arrow_left';
        if (collapseText) collapseText.textContent = 'Collapse';
        localStorage.setItem('sidebarCollapsed', 'false');
    } else {
        // Collapse
        sidebar.classList.add('sidebar-collapsed');
        if (collapseIcon) collapseIcon.textContent = 'keyboard_double_arrow_right';
        if (collapseText) collapseText.textContent = 'Expand';
        localStorage.setItem('sidebarCollapsed', 'true');
    }
}

// Restore sidebar state on page load (backup - primary is in header.php)
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const collapseIcon = document.getElementById('collapseIcon');
    const collapseText = document.getElementById('collapseText');
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

    if (!sidebar) return;

    if (isCollapsed) {
        sidebar.classList.add('sidebar-collapsed');
        if (collapseIcon) collapseIcon.textContent = 'keyboard_double_arrow_right';
        if (collapseText) collapseText.textContent = 'Expand';
    } else {
        sidebar.classList.remove('sidebar-collapsed');
        if (collapseIcon) collapseIcon.textContent = 'keyboard_double_arrow_left';
        if (collapseText) collapseText.textContent = 'Collapse';
    }
});
</script>

<style>
/* Removed old sidebar-compact styles - now using sidebar-collapsed from header.php */
</style>

<!-- Floating Chat Icon - Responsive -->
<div id="notification-fab" class="fixed bottom-4 right-4 md:bottom-6 md:right-6 z-50 transition-all duration-300">
    <button onclick="toggleNotificationPanel()" 
            title="Open Chat Room"
            class="bg-blue-600 hover:bg-blue-700 text-white rounded-full p-3 md:p-4 shadow-lg transition-all duration-300 hover:scale-110 active:scale-95 relative">
        <svg class="w-5 h-5 md:w-6 md:h-6" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
        </svg>
        <span id="notification-badge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center hidden">0</span>
    </button>
</div>

<!-- Notification Panel - Responsive -->
<div id="notification-panel" class="fixed top-0 right-0 h-full w-full sm:w-96 md:w-[400px] lg:w-[440px] bg-white dark:bg-slate-900 shadow-2xl transform translate-x-full transition-transform duration-300 z-[60] flex flex-col">
    <!-- Panel Header -->
    <div class="bg-gradient-to-r from-blue-600 to-blue-500 text-white px-3 py-3 md:px-4 md:py-4 flex justify-between items-center shadow-lg">
        <div class="flex items-center gap-2">
            <button id="back-to-chat-btn" onclick="backToChat()" class="hover:bg-blue-700 rounded-full p-1 transition-colors hidden" title="Back to chat">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>
            <h3 class="text-lg font-semibold flex items-center gap-2">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                </svg>
                Chat Room
            </h3>
        </div>
        <button onclick="toggleNotificationPanel()" class="hover:bg-blue-700 rounded-full p-1 transition-colors">
            <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>

    <!-- Panel Content - Responsive -->
    <div id="notification-content" class="flex-1 overflow-y-auto p-3 md:p-4 bg-gray-50 dark:bg-slate-800">
        <!-- Content will be loaded here -->
    </div>

    <!-- Chat Input Form - Responsive -->
    <div class="border-t border-slate-200 dark:border-slate-700 p-3 md:p-4 bg-white dark:bg-slate-900">
        <!-- Reply Preview Area -->
        <div id="reply-preview-container" class="mb-2 md:mb-3 hidden">
            <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 rounded px-2 py-1.5 md:px-3 md:py-2 flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0 flex gap-2">
                    <div class="flex-1">
                        <div class="flex items-center gap-1 mb-1">
                            <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="text-xs font-semibold text-blue-700">Replying to <span id="reply-to-user"></span></span>
                        </div>
                        <p id="reply-preview-text" class="text-xs text-gray-700 line-clamp-2"></p>
                    </div>
                    <div id="reply-image-preview" class="hidden flex-shrink-0">
                        <img id="reply-preview-img" src="" alt="Preview" class="w-16 h-16 rounded object-cover cursor-pointer border-2 border-blue-300 hover:border-blue-500 transition-colors" onclick="openImageModal(this.src)">
                    </div>
                </div>
                <button type="button" onclick="cancelReply()" class="text-gray-500 hover:text-gray-700 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <!-- Image Preview Area - Responsive -->
        <div id="image-preview-container" class="mb-2 md:mb-3 hidden">
            <div class="relative inline-block">
                <img id="image-preview" src="" alt="Preview" class="max-h-24 md:max-h-32 rounded-lg border-2 border-gray-300 dark:border-slate-600">
                <button type="button" onclick="removeImagePreview()" class="absolute -top-1 -right-1 md:-top-2 md:-right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <form id="quick-request-form" class="flex gap-1.5 md:gap-2 items-end" enctype="multipart/form-data">
            <input type="file" id="chat-image-input" name="image" accept="image/jpeg,image/jpg,image/png,image/gif" class="hidden">
            <input type="hidden" id="reply-to-input" name="reply_to" value="">
            
            <button type="button" onclick="document.getElementById('chat-image-input').click()" title="Upload Image"
                    class="bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-600 dark:text-gray-300 p-2 md:p-3 rounded-full transition-all flex-shrink-0">
                <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </button>
            
            <textarea name="message" 
                      placeholder="Type your message..." 
                      rows="1" 
                      class="flex-1 border-2 border-gray-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white rounded-full px-3 py-2 md:px-4 md:py-2 text-sm md:text-base focus:ring-2 focus:ring-blue-400 focus:border-blue-400 resize-none max-h-24 md:max-h-32"></textarea>
            <button type="submit" 
                    class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-medium p-2 md:p-3 rounded-full transition-all shadow-md hover:shadow-lg active:scale-95 flex-shrink-0">
                <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                </svg>
            </button>
        </form>
    </div>
</div>

<!-- Image Modal - On Top of Everything -->
<div id="image-modal-backdrop" class="fixed inset-0 bg-black bg-opacity-95 hidden" style="z-index: 999998;"></div>
<div id="image-modal" class="fixed inset-0 hidden flex items-center justify-center p-4" style="z-index: 999999;">
    <button onclick="closeImageModal()" class="fixed top-4 right-4 md:top-6 md:right-6 text-white hover:text-gray-300 bg-black/20 rounded-full p-2 md:p-3 transition-colors hover:bg-black/40" title="Close" style="z-index: 1000000;">
        <svg class="w-6 h-6 md:w-8 md:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </button>
    <img id="modal-image" src="" alt="Full size" class="max-w-full max-h-full object-contain cursor-pointer" onclick="event.stopPropagation()">
</div>

<!-- Overlay - Responsive -->
<div id="notification-overlay" class="fixed inset-0 bg-black bg-opacity-60 z-50 hidden transition-opacity duration-300" onclick="toggleNotificationPanel()"></div>

<script src="assets/js/main.js"></script>
<script>
// Notification Panel Functions
function toggleNotificationPanel() {
    var panel = document.getElementById('notification-panel');
    var overlay = document.getElementById('notification-overlay');
    var fab = document.getElementById('notification-fab');
    
    if (panel.classList.contains('translate-x-full')) {
        // Open panel
        panel.classList.remove('translate-x-full');
        overlay.classList.remove('hidden');
        fab.style.display = 'none'; // Hide icon when panel is open
        loadNotifications();
        
        // Initialize form handlers only once
        if (!chatFormInitialized) {
            initializeChatForm();
        }
    } else {
        // Close panel
        panel.classList.add('translate-x-full');
        overlay.classList.add('hidden');
        fab.style.display = 'block'; // Show icon when panel is closed
    }
}

function loadNotifications() {
    console.log('Loading notifications...');
    fetch('notifications.php?action=panel&_=' + Date.now(), {
        cache: 'no-store',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(function(data) {
        console.log('Received data:', data);
        console.log('HTML length:', data.html ? data.html.length : 0);
        
        if (data && data.success) {
            var content = document.getElementById('notification-content');
            if (content) {
                content.innerHTML = data.html || '<p class="text-center text-gray-500 py-8">No messages</p>';
                console.log('Content updated, innerHTML length:', content.innerHTML.length);
                
                // Add reply buttons to each message
                var messages = content.querySelectorAll('[data-message-id]');
                messages.forEach(function(msgDiv) {
                    var messageBox = msgDiv.querySelector('.bg-white.border');
                    if (messageBox) {
                        // Extract message data from attributes
                        var messageId = msgDiv.getAttribute('data-message-id');
                        var userName = msgDiv.getAttribute('data-message-user');
                        var messageTextEl = msgDiv.querySelector('.text-gray-800.text-sm');
                        var imageEl = msgDiv.querySelector('img[alt="Attachment"]');
                        var hasImage = imageEl !== null;
                        var imageSrc = hasImage ? imageEl.src : null;
                        
                        var messageText = messageTextEl ? messageTextEl.textContent.trim() : '';
                        
                        // Create reply button
                        var replyBtn = document.createElement('button');
                        replyBtn.type = 'button';
                        replyBtn.className = 'text-xs text-gray-500 hover:text-blue-600 mt-2 flex items-center gap-1 transition-colors';
                        replyBtn.innerHTML = '<svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 11-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg><span>Reply</span>';
                        
                        // Add click handler
                        replyBtn.onclick = function(e) {
                            e.preventDefault();
                            replyToMessage(messageId, userName, messageText, hasImage, imageSrc);
                        };
                        
                        // Append to message box
                        messageBox.appendChild(replyBtn);
                    }
                });
                
                // Auto-scroll to bottom like a chat room
                setTimeout(function() {
                    content.scrollTop = content.scrollHeight;
                }, 100);
            } else {
                console.error('notification-content element not found');
            }
            
            // Hide badge after reading
            var badge = document.getElementById('notification-badge');
            if (badge) {
                badge.classList.add('hidden');
            }
            
            // Update count
            updateUnreadCount();
        } else {
            console.error('Invalid response data:', data);
        }
    })
    .catch(function(error) {
        console.error('Error loading notifications:', error);
    });
}

function updateUnreadCount() {
    fetch('notifications.php?action=count&_=' + Date.now(), {
        cache: 'no-store',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        var badge = document.getElementById('notification-badge');
        if (data.count > 0) {
            badge.textContent = data.count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    })
    .catch(function(error) {
        console.error('Error updating count:', error);
    });
}

function backToChat() {
    var backBtn = document.getElementById('back-to-chat-btn');
    backBtn.classList.add('hidden');
    loadNotifications();
}

// Image handling functions
function removeImagePreview() {
    var previewContainer = document.getElementById('image-preview-container');
    var previewImg = document.getElementById('image-preview');
    var fileInput = document.getElementById('chat-image-input');
    
    previewContainer.classList.add('hidden');
    previewImg.src = '';
    fileInput.value = '';
    pastedChatImage = null;
}

function openImageModal(src) {
    var modal = document.getElementById('image-modal');
    var backdrop = document.getElementById('image-modal-backdrop');
    var modalImg = document.getElementById('modal-image');
    
    modalImg.src = src;
    modal.classList.remove('hidden');
    backdrop.classList.remove('hidden');
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
}

function closeImageModal() {
    var modal = document.getElementById('image-modal');
    var backdrop = document.getElementById('image-modal-backdrop');
    
    modal.classList.add('hidden');
    backdrop.classList.add('hidden');
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
}

// Close image modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('image-modal');
        if (modal && !modal.classList.contains('hidden')) {
            closeImageModal();
        }
    }
});

// Close modal when clicking backdrop
document.addEventListener('click', function(e) {
    var backdrop = document.getElementById('image-modal-backdrop');
    if (e.target === backdrop) {
        closeImageModal();
    }
});

function handleChatImageActivation(e) {
    var img = e.target.closest('img[data-chat-image="1"]');
    if (!img) return;

    var notificationContent = document.getElementById('notification-content');
    if (!notificationContent || !notificationContent.contains(img)) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
    }
    openImageModal(img.currentSrc || img.src);
}

document.addEventListener('click', handleChatImageActivation, true);
document.addEventListener('touchstart', handleChatImageActivation, true);

// Reply handling functions
function replyToMessage(messageId, userName, messageText, hasAttachment, attachmentSrc) {
    var replyContainer = document.getElementById('reply-preview-container');
    var replyToInput = document.getElementById('reply-to-input');
    var replyToUser = document.getElementById('reply-to-user');
    var replyPreviewText = document.getElementById('reply-preview-text');
    var replyImagePreview = document.getElementById('reply-image-preview');
    var replyPreviewImg = document.getElementById('reply-preview-img');
    var messageInput = document.querySelector('[name="message"]');
    
    // Set reply data
    replyToInput.value = messageId;
    replyToUser.textContent = userName;
    
    // Handle image preview
    if (hasAttachment && attachmentSrc) {
        replyPreviewImg.src = attachmentSrc;
        replyImagePreview.classList.remove('hidden');
        
        if (!messageText) {
            replyPreviewText.innerHTML = '<span class="italic text-gray-600">📷 Image</span>';
        } else {
            var truncated = messageText.length > 60 ? messageText.substring(0, 60) + '...' : messageText;
            replyPreviewText.textContent = truncated;
        }
    } else {
        replyImagePreview.classList.add('hidden');
        replyPreviewImg.src = '';
        
        if (messageText) {
            var truncated = messageText.length > 100 ? messageText.substring(0, 100) + '...' : messageText;
            replyPreviewText.textContent = truncated;
        } else {
            replyPreviewText.textContent = 'Message';
        }
    }
    
    // Show reply preview
    replyContainer.classList.remove('hidden');
    
    // Focus on message input
    messageInput.focus();
}

function cancelReply() {
    var replyContainer = document.getElementById('reply-preview-container');
    var replyToInput = document.getElementById('reply-to-input');
    var replyImagePreview = document.getElementById('reply-image-preview');
    var replyPreviewImg = document.getElementById('reply-preview-img');
    
    replyContainer.classList.add('hidden');
    replyToInput.value = '';
    replyImagePreview.classList.add('hidden');
    replyPreviewImg.src = '';
}

var chatFormInitialized = false;
var pastedChatImage = null; // Stores image pasted from clipboard

function initializeChatForm() {
    if (chatFormInitialized) return; // Prevent duplicate initialization
    
    var quickForm = document.getElementById('quick-request-form');
    if (!quickForm) return;
    
    var messageInput = quickForm.querySelector('[name="message"]');
    if (!messageInput) return;
    
    // Mark as initialized
    chatFormInitialized = true;
    
    // Handle image selection
    var imageInput = document.getElementById('chat-image-input');
    if (imageInput) {
        imageInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                // Validate file size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image size must be less than 2MB', 'error');
                    this.value = '';
                    return;
                }
                
                // Validate file type
                var validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    showToast('Only JPG, PNG, and GIF images are allowed', 'error');
                    this.value = '';
                    return;
                }
                
                // Show preview
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById('image-preview');
                    var previewContainer = document.getElementById('image-preview-container');
                    
                    preview.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Handle pasted images from clipboard
    messageInput.addEventListener('paste', function(e) {
        var items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                e.preventDefault();
                var file = items[i].getAsFile();
                if (!file) continue;
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image size must be less than 2MB', 'error');
                    return;
                }
                // Clear any previously selected file input image
                var fileInput = document.getElementById('chat-image-input');
                if (fileInput) fileInput.value = '';
                pastedChatImage = file;
                var reader = new FileReader();
                reader.onload = function(ev) {
                    var preview = document.getElementById('image-preview');
                    var previewContainer = document.getElementById('image-preview-container');
                    preview.src = ev.target.result;
                    previewContainer.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
                return; // Only handle first image
            }
        }
    });

    // Handle Enter key to send (Shift+Enter for new line)
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            quickForm.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });
    
    // Auto-resize textarea
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 128) + 'px';
    });
    
    // Handle form submission
    quickForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        var message = messageInput.value.trim();
        var imageInput = document.getElementById('chat-image-input');
        var hasImage = (imageInput && imageInput.files.length > 0) || pastedChatImage !== null;
        
        // Require at least message or image
        if (!message && !hasImage) {
            return;
        }
        
        // Disable submit button to prevent double-click
        var submitBtn = quickForm.querySelector('button[type="submit"]');
        var isSubmitting = false;
        
        if (isSubmitting) {
            return; // Prevent duplicate submissions
        }
        
        isSubmitting = true;
        if (submitBtn) {
            submitBtn.disabled = true;
        }
        
        var formData = new FormData();
        formData.append('message', message);
        
        // Add image if present (prefer pasted image, fall back to file input)
        if (pastedChatImage) {
            formData.append('image', pastedChatImage, 'pasted_image.png');
        } else if (imageInput && imageInput.files.length > 0) {
            formData.append('image', imageInput.files[0]);
        }
        
        // Add reply_to if present
        var replyToInput = document.getElementById('reply-to-input');
        if (replyToInput && replyToInput.value) {
            formData.append('reply_to', replyToInput.value);
        }
        
        fetch('notifications.php?action=add', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(function(data) {
            if (data && data.success) {
                // Clear form immediately
                messageInput.value = '';
                messageInput.style.height = 'auto';
                
                // Clear pasted image
                pastedChatImage = null;
                
                // Clear image preview
                removeImagePreview();
                
                // Clear reply preview
                cancelReply();
                
                // Reload notifications immediately without delay
                loadNotifications();
            } else {
                console.error('Server error:', data);
                var errorMsg = (data && data.message) ? data.message : 'Failed to send message';
                showToast(errorMsg, 'error');
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            showToast('Error sending message: ' + error.message, 'error');
        })
        .finally(function() {
            // Re-enable submit button
            isSubmitting = false;
            if (submitBtn) {
                submitBtn.disabled = false;
            }
        });
    });
    
}

function showToast(message, type) {
    type = type || 'success';
    var toast = document.createElement('div');
    toast.className = 'fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white ' + 
                      (type === 'success' ? 'bg-green-600' : 'bg-red-600');
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(function() {
        toast.remove();
    }, 3000);
}

// Initialize everything when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize chat form
    initializeChatForm();
    
    // Load initial unread count
    updateUnreadCount();
    
    // Refresh unread count every 30 seconds
    setInterval(function() {
        updateUnreadCount();
    }, 30000);
});
</script>
</body>
</html>
