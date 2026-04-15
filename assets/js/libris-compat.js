/* assets/js/libris-compat.js
 * Legacy compatibility bootstrap (IE11-safe syntax).
 * Must run before deferred application scripts.
 */
(function (window, document) {
  if (!window || !document) {
    return;
  }

  var compat = window.LibrisCompat || {};

  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  if (!Element.prototype.matches) {
    Element.prototype.matches =
      Element.prototype.msMatchesSelector ||
      Element.prototype.webkitMatchesSelector ||
      function (selector) {
        var nodeList = (this.document || this.ownerDocument).querySelectorAll(selector);
        var i = 0;
        while (nodeList[i] && nodeList[i] !== this) {
          i += 1;
        }
        return !!nodeList[i];
      };
  }

  if (!Element.prototype.closest) {
    Element.prototype.closest = function (selector) {
      var element = this;
      while (element && element.nodeType === 1) {
        if (element.matches(selector)) {
          return element;
        }
        element = element.parentElement || element.parentNode;
      }
      return null;
    };
  }

  if (window.NodeList && !NodeList.prototype.forEach) {
    NodeList.prototype.forEach = function (callback, thisArg) {
      var i;
      for (i = 0; i < this.length; i += 1) {
        callback.call(thisArg, this[i], i, this);
      }
    };
  }

  if (!String.prototype.includes) {
    String.prototype.includes = function (search, start) {
      if (typeof start !== 'number') {
        start = 0;
      }
      if (start + search.length > this.length) {
        return false;
      }
      return this.indexOf(search, start) !== -1;
    };
  }

  if (!String.prototype.startsWith) {
    String.prototype.startsWith = function (search, pos) {
      var position = pos || 0;
      return this.substr(position, search.length) === search;
    };
  }

  if (!String.prototype.endsWith) {
    String.prototype.endsWith = function (search, thisLen) {
      var len = thisLen;
      if (len === undefined || len > this.length) {
        len = this.length;
      }
      return this.substring(len - search.length, len) === search;
    };
  }

  if (!Array.from) {
    Array.from = function (arrayLike) {
      var arr = [];
      var i;
      if (!arrayLike) {
        return arr;
      }
      for (i = 0; i < arrayLike.length; i += 1) {
        arr.push(arrayLike[i]);
      }
      return arr;
    };
  }

  (function () {
    var lastTime = 0;
    var vendors = ['ms', 'moz', 'webkit', 'o'];
    var i;

    for (i = 0; i < vendors.length && !window.requestAnimationFrame; i += 1) {
      window.requestAnimationFrame = window[vendors[i] + 'RequestAnimationFrame'];
      window.cancelAnimationFrame =
        window[vendors[i] + 'CancelAnimationFrame'] ||
        window[vendors[i] + 'CancelRequestAnimationFrame'];
    }

    if (!window.requestAnimationFrame) {
      window.requestAnimationFrame = function (callback) {
        var currTime = new Date().getTime();
        var timeToCall = Math.max(0, 16 - (currTime - lastTime));
        var id = window.setTimeout(function () {
          callback(currTime + timeToCall);
        }, timeToCall);
        lastTime = currTime + timeToCall;
        return id;
      };
    }

    if (!window.cancelAnimationFrame) {
      window.cancelAnimationFrame = function (id) {
        window.clearTimeout(id);
      };
    }
  })();

  compat.classListSupported = !!(document.documentElement && document.documentElement.classList);
  compat.addClass = function (element, className) {
    if (!element || !className) {
      return;
    }
    if (element.classList) {
      element.classList.add(className);
      return;
    }
    if ((' ' + element.className + ' ').indexOf(' ' + className + ' ') === -1) {
      element.className = (element.className ? element.className + ' ' : '') + className;
    }
  };
  compat.removeClass = function (element, className) {
    var updated;
    if (!element || !className) {
      return;
    }
    if (element.classList) {
      element.classList.remove(className);
      return;
    }
    updated = (' ' + element.className + ' ').replace(' ' + className + ' ', ' ');
    element.className = updated.replace(/^\s+|\s+$/g, '');
  };
  compat.toggleClass = function (element, className, force) {
    if (force === true) {
      compat.addClass(element, className);
      return true;
    }
    if (force === false) {
      compat.removeClass(element, className);
      return false;
    }
    if (!element) {
      return false;
    }
    if (element.classList) {
      return element.classList.toggle(className);
    }
    if ((' ' + element.className + ' ').indexOf(' ' + className + ' ') > -1) {
      compat.removeClass(element, className);
      return false;
    }
    compat.addClass(element, className);
    return true;
  };

  compat.missing = compat.missing || {};
  compat.missing.fetch = typeof window.fetch !== 'function';
  compat.missing.promise = typeof window.Promise !== 'function';
  compat.missing.classList = !compat.classListSupported;

  compat.hasCriticalMissing = false;
  for (var key in compat.missing) {
    if (hasOwn(compat.missing, key) && compat.missing[key]) {
      compat.hasCriticalMissing = true;
      break;
    }
  }

  window.LibrisCompat = compat;
})(window, document);
