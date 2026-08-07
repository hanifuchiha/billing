/**
 * QTS CRM - Simple Modal Management
 * Version: 3.0.0 - Back to basics for reliability
 */

console.log('🔧 Loading QTS Simple Modal System v3.0...');

// Simple and reliable modal management
window.QTSModals = {
  
  /**
   * Simple initialization - let Bootstrap do its job
   */
  init: function() {
    console.log('🚀 Initializing Simple Modal System...');
    
    // Just ensure Bootstrap is available
    if (typeof bootstrap === 'undefined') {
      console.warn('⚠️ Bootstrap not found');
      return;
    }
    
    // Simple cleanup on modal close
    this.initCleanup();
    
    console.log('✅ Simple Modal System ready');
  },
  
  /**
   * Basic cleanup only
   */
  initCleanup: function() {
    document.addEventListener('hidden.bs.modal', function(e) {
      setTimeout(() => {
        // Simple backdrop cleanup
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
        
        // Simple body cleanup
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
          document.body.classList.remove('modal-open');
          document.body.style.overflow = '';
          document.body.style.paddingRight = '';
        }
      }, 100);
    });
  },
  
  /**
   * Simple force cleanup
   */
  cleanup: function() {
    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
  }
};

// Initialize when ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    QTSModals.init();
  });
} else {
  QTSModals.init();
}

// Simple global cleanup
window.cleanupAllModalBackdrops = QTSModals.cleanup;

console.log('📦 QTS Simple Modal System v3.0 Ready');