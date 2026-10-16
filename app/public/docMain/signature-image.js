(() => {
  // Use identical ink bounds for preview and the PNG sent to the PDF service.
  window.getSignatureCanvas = () => {
    const source = document.getElementById('pad');
    const {width, height} = source;
    const data = source.getContext('2d').getImageData(0, 0, width, height).data;
    let left = width, top = height, right = -1, bottom = -1;
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const i = (y * width + x) * 4;
        if (data[i + 3] > 16 && Math.min(data[i], data[i + 1], data[i + 2]) < 245) {
          left = Math.min(left, x); right = Math.max(right, x);
          top = Math.min(top, y); bottom = Math.max(bottom, y);
        }
      }
    }
    if (right < left) return source;
    const padding = Math.max(2, Math.round(Math.min(width, height) * .015));
    left = Math.max(0, left - padding); top = Math.max(0, top - padding);
    right = Math.min(width - 1, right + padding); bottom = Math.min(height - 1, bottom + padding);
    const cropped = document.createElement('canvas');
    cropped.width = right - left + 1; cropped.height = bottom - top + 1;
    cropped.getContext('2d').drawImage(source, left, top, cropped.width, cropped.height, 0, 0, cropped.width, cropped.height);
    return cropped;
  };
})();
