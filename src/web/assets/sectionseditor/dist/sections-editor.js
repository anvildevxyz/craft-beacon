class SectionsEditor {
  constructor(containerEl) {
    this.container = containerEl;
    this.searchInput = containerEl.querySelector('.sections-search');
    this.sectionItems = containerEl.querySelectorAll('.section-item');
    this.checkboxes = containerEl.querySelectorAll('.section-checkbox');
    this.chipsContainer = containerEl.querySelector('.sections-chips');
    this.fieldName = this.chipsContainer.dataset.fieldName;
    this.hiddenInputsContainer = containerEl.querySelector('.sections-hidden');
    this.draggedElement = null;

    this.init();
  }

  init() {
    this.attachSearchListener();
    this.attachCheckboxListeners();
    this.attachAddButtonListeners();
    this.attachChipRemoveListeners();
    this.attachDragListeners();
  }

  attachSearchListener() {
    this.searchInput.addEventListener('input', (e) => {
      const searchTerm = e.target.value.toLowerCase();
      this.sectionItems.forEach((item) => {
        const label = item.querySelector('.section-label').textContent.toLowerCase();
        const isMatch = label.includes(searchTerm);
        item.style.display = isMatch ? '' : 'none';
      });
    });
  }

  attachCheckboxListeners() {
    this.checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', (e) => {
        const handle = e.target.value;
        if (e.target.checked) {
          this.addChip(handle, e.target.parentElement.querySelector('.section-label').textContent);
        } else {
          this.removeChip(handle);
        }
        this.updateHiddenInputs();
      });
    });
  }

  attachAddButtonListeners() {
    const addBtns = this.container.querySelectorAll('.section-add-btn');
    addBtns.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const handle = btn.dataset.handle;
        const checkbox = this.container.querySelector(`[value="${handle}"]`);
        const label = this.container.querySelector(`[for="section-${handle}"]`).textContent;

        if (!checkbox.checked) {
          checkbox.checked = true;
          this.addChip(handle, label);
          this.updateHiddenInputs();
        }

        // Optional: highlight the chip briefly
        const chip = this.chipsContainer.querySelector(`[data-handle="${handle}"]`);
        if (chip) {
          chip.classList.add('pulse');
          setTimeout(() => chip.classList.remove('pulse'), 600);
        }
      });
    });
  }

  attachChipRemoveListeners() {
    this.chipsContainer.addEventListener('click', (e) => {
      if (e.target.closest('.chip-remove')) {
        e.preventDefault();
        const handle = e.target.dataset.handle;
        const checkbox = this.container.querySelector(`[value="${handle}"]`);
        checkbox.checked = false;
        this.removeChip(handle);
        this.updateHiddenInputs();
      }
    });
  }

  attachDragListeners() {
    this.chipsContainer.addEventListener('dragstart', (e) => {
      if (e.target.closest('.section-chip')) {
        this.draggedElement = e.target.closest('.section-chip');
        this.draggedElement.classList.add('drag-item');
        e.dataTransfer.effectAllowed = 'move';
      }
    });

    this.chipsContainer.addEventListener('dragend', (e) => {
      this.draggedElement?.classList.remove('drag-item');
      this.chipsContainer.classList.remove('drag-over');
      this.draggedElement = null;
    });

    this.chipsContainer.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      this.chipsContainer.classList.add('drag-over');
    });

    this.chipsContainer.addEventListener('dragleave', (e) => {
      if (e.target === this.chipsContainer) {
        this.chipsContainer.classList.remove('drag-over');
      }
    });

    this.chipsContainer.addEventListener('drop', (e) => {
      e.preventDefault();
      this.chipsContainer.classList.remove('drag-over');

      if (!this.draggedElement) return;

      const afterElement = this.getDragAfterElement(this.chipsContainer, e.clientX, e.clientY);
      if (afterElement == null) {
        this.chipsContainer.appendChild(this.draggedElement);
      } else {
        this.chipsContainer.insertBefore(this.draggedElement, afterElement);
      }

      this.updateHiddenInputs();
    });
  }

  getDragAfterElement(container, x, y) {
    const draggableElements = [...container.querySelectorAll('.section-chip:not(.drag-item)')];

    return draggableElements.reduce(
      (closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      },
      { offset: Number.NEGATIVE_INFINITY }
    ).element;
  }

  addChip(handle, label) {
    // Don't add if already exists
    if (this.chipsContainer.querySelector(`[data-handle="${handle}"]`)) {
      return;
    }

    const chip = document.createElement('div');
    chip.className = 'section-chip';
    chip.draggable = true;
    chip.dataset.handle = handle;
    chip.innerHTML = `
      <span class="chip-label">${this.escapeHtml(label)}</span>
      <button type="button" class="chip-remove" data-handle="${handle}" title="Remove section">×</button>
    `;

    this.chipsContainer.appendChild(chip);
  }

  removeChip(handle) {
    const chip = this.chipsContainer.querySelector(`[data-handle="${handle}"]`);
    chip?.remove();
  }

  updateHiddenInputs() {
    // Clear existing hidden inputs
    this.hiddenInputsContainer.innerHTML = '';

    // Add new hidden inputs in order
    this.chipsContainer.querySelectorAll('.section-chip').forEach((chip) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = `${this.fieldName}[]`;
      input.value = chip.dataset.handle;
      this.hiddenInputsContainer.appendChild(input);
    });
  }

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

// Initialize all editors on the page
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.beacon-sections-editor').forEach((el) => {
    new SectionsEditor(el);
  });
});
