(() => {
  const qs = (root, sel) => root.querySelector(sel);
  const qsa = (root, sel) => Array.from(root.querySelectorAll(sel));

  const getDataset = (el, key, fallback = "") => {
    const v = el.dataset[key];
    return typeof v === "string" && v.length > 0 ? v : fallback;
  };

  const getOptionalPositiveIntDataset = (el, key) => {
    const v = getDataset(el, key, "");
    const t = v.trim();
    if (!t) return null;
    // be forgiving: accept "14", " 14 ", "iblock=14"
    const m = t.match(/(\d+)/);
    if (!m) return null;
    const n = Number(m[1]);
    return Number.isFinite(n) && n > 0 ? n : null;
  };

  const getBooleanDataset = (el, key, fallback = false) => {
    const v = getDataset(el, key, "");
    const t = v.trim().toLowerCase();
    if (!t) return fallback;
    if (["1", "true", "y", "yes", "on"].includes(t)) return true;
    if (["0", "false", "n", "no", "off"].includes(t)) return false;
    return fallback;
  };

  const withQuery = (url, params) => {
    const u = new URL(url, window.location.origin);
    for (const [k, v] of Object.entries(params)) {
      if (v === null || v === undefined || v === "") continue;
      u.searchParams.set(k, String(v));
    }
    return u.toString();
  };

  const parseHashParams = () => {
    const hash = window.location.hash || "";
    const s = hash.startsWith("#") ? hash.slice(1) : hash;
    const params = new URLSearchParams(s);
    return params;
  };

  const setHashParam = (key, value) => {
    const params = parseHashParams();
    if (value === null || value === undefined || value === "") {
      params.delete(key);
    } else {
      params.set(key, String(value));
    }
    const next = params.toString();
    window.location.hash = next ? `#${next}` : "";
  };

  const getProductIdFromLocation = (paramName) => {
    // 1) hash
    const hashParams = parseHashParams();
    const vHash = hashParams.get(paramName);
    if (vHash) {
      const m = String(vHash).match(/(\d+)/);
      if (m) {
        const n = Number(m[1]);
        if (Number.isFinite(n) && n > 0) return n;
      }
    }

    // 2) query
    const url = new URL(window.location.href);
    const vQuery = url.searchParams.get("productId");
    if (vQuery) {
      const m = String(vQuery).match(/(\d+)/);
      if (m) {
        const n = Number(m[1]);
        if (Number.isFinite(n) && n > 0) return n;
      }
    }

    return null;
  };

  /**
   * CRM-forms обычно читают только query (?productId=...).
   * Мы используем hash (#product=...), поэтому синхронизируем hash -> query без перезагрузки.
   *
   * Важно: если hash-параметра нет, query НЕ трогаем (чтобы работали прямые ссылки вида ?productId=...).
   */
  const syncHashProductToQuery = (paramName) => {
    const hashParams = parseHashParams();
    const vHash = hashParams.get(paramName);
    if (!vHash) return;

    const m = String(vHash).match(/(\d+)/);
    if (!m) return;
    const id = Number(m[1]);
    if (!Number.isFinite(id) || id <= 0) return;

    const u = new URL(window.location.href);
    const cur = u.searchParams.get("productId");
    if (cur && cur.trim() === String(id)) return;

    u.searchParams.set("productId", String(id));
    // replaceState does not trigger reload and keeps hash intact
    window.history.replaceState(null, "", u.toString());
  };

  const fetchJson = async (url) => {
    const resp = await fetch(url, { method: "GET" });
    const text = await resp.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Invalid JSON from ${url}: ${text.slice(0, 200)}`);
    }
    if (!resp.ok) {
      throw new Error(data?.error ? `${data.error}` : `HTTP ${resp.status}`);
    }
    return data;
  };

  const debounce = (fn, ms) => {
    let t = null;
    return (...args) => {
      if (t) clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  const renderCard = (item, opts = { showDurationWorkDays: false }) => {
    const el = document.createElement("div");
    el.className = "ap-card";
    el.innerHTML = `
      <div class="ap-card__name"></div>
      <div class="ap-card__meta">
        <div class="ap-card__hours"></div>
        <div class="ap-card__price"></div>
      </div>
    `;
    qs(el, ".ap-card__name").textContent = item.name ?? "";
    qs(el, ".ap-card__hours").textContent = opts.showDurationWorkDays
      ? item.durationWorkDays != null
        ? `${item.durationWorkDays} раб.дн.`
        : ""
      : item.hours != null
        ? `${item.hours} уч.ч.`
        : "";
    qs(el, ".ap-card__price").textContent = item.priceDisplay ?? "";
    return el;
  };

  const initSearch = (root) => {
    const apiBase = getDataset(root, "apApiBase");
    const productParam = getDataset(root, "apProductParam", "product");
    const detailPath = getDataset(root, "apDetailPath", "");
    const iblockId = getOptionalPositiveIntDataset(root, "apIblockId");
    const form = qs(root, ".ap-search__form");
    const input = qs(root, ".ap-search__input");
    const button = qs(root, ".ap-search__button");
    const dropdown = qs(root, ".ap-search__dropdown");
    const sectionId =
      getOptionalPositiveIntDataset(root, "apSectionId") ??
      (form ? getOptionalPositiveIntDataset(form, "apSectionId") : null);

    const close = () => {
      dropdown.hidden = true;
      dropdown.innerHTML = "";
    };

    const open = () => {
      dropdown.hidden = false;
    };

    const shake = () => {
      input.classList.remove("ap-is-shaking");
      // force reflow
      void input.offsetWidth;
      input.classList.add("ap-is-shaking");
      setTimeout(() => input.classList.remove("ap-is-shaking"), 500);
    };

    const doSearch = debounce(async () => {
      const q = (input.value || "").trim();
      if (q.length < 3) {
        close();
        return;
      }

      try {
        const url = withQuery(`${apiBase}/search`, { q, iblockId, sectionId });
        const data = await fetchJson(url);
        const items = Array.isArray(data.items) ? data.items : [];
        dropdown.innerHTML = "";
        if (items.length === 0) {
          close();
          return;
        }
        for (const it of items) {
          const row = document.createElement("div");
          row.className = "ap-search__item";
          const meta = [it.hours != null ? `${it.hours} уч.ч.` : null, it.priceDisplay ?? null]
            .filter(Boolean)
            .join(" · ");
          row.textContent = meta ? `${it.name} — ${meta}` : `${it.name}`;
          row.addEventListener("click", () => {
            const path = (detailPath || "").trim();
            if (path) {
              const base = withQuery(path, { productId: it.id });
              window.location.href = `${base}#${productParam}=${it.id}`;
              return;
            }
            setHashParam(productParam, it.id);
            syncHashProductToQuery(productParam);
            close();
          });
          dropdown.appendChild(row);
        }
        open();
      } catch (e) {
        console.error(e);
        close();
      }
    }, 300);

    const trigger = () => {
      const q = (input.value || "").trim();
      if (!q) {
        input.focus();
        shake();
        return;
      }
      doSearch();
    };

    input.addEventListener("input", doSearch);
    input.addEventListener("focus", () => {
      if (dropdown.childElementCount > 0) open();
    });
    if (form) {
      form.addEventListener("submit", (e) => {
        e.preventDefault(); // кнопка "Найти" только триггерит наш dropdown, без навигации
        trigger();
      });
    }
    if (button) {
      button.addEventListener("click", (e) => {
        e.preventDefault();
        trigger();
      });
      button.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          trigger();
        }
      });
    }
    document.addEventListener("click", (e) => {
      if (!root.contains(e.target)) close();
    });
  };

  const initCatalog = (root) => {
    const apiBase = getDataset(root, "apApiBase");
    const productParam = getDataset(root, "apProductParam", "product");
    const detailPath = getDataset(root, "apDetailPath", "");
    const inner = qs(root, ".ap-catalog__inner");
    const columnsRaw = getOptionalPositiveIntDataset(inner ?? root, "apColumns");
    const columns = columnsRaw === 3 || columnsRaw === 5 ? columnsRaw : 5;
    const iblockId = getOptionalPositiveIntDataset(root, "apIblockId");
    const grid = qs(root, ".ap-catalog__grid");
    const pager = qs(root, ".ap-catalog__pager");
    const showDurationWorkDays =
      getBooleanDataset(pager ?? root, "apShowDurationWorkDays", false) ||
      getBooleanDataset(root, "apShowDurationWorkDays", false);
    const sectionId =
      getOptionalPositiveIntDataset(root, "apSectionId") ??
      (grid ? getOptionalPositiveIntDataset(grid, "apSectionId") : null);

    const chunkSize = 50;
    let chunkStart = 0;
    let chunkItems = [];
    const chunkCache = new Map(); // start -> { items, total }
    let total = null;
    let uiPage = 1; // global page
    const uiPerPage = 15;

    root.classList.remove("ap-cols-3", "ap-cols-5");
    root.classList.add(columns === 3 ? "ap-cols-3" : "ap-cols-5");

    const getChunk = async (start) => {
      const key = Math.max(0, start);
      const cached = chunkCache.get(key);
      if (cached) return cached;
      const url = withQuery(`${apiBase}/products`, { start: key, iblockId, sectionId });
      const data = await fetchJson(url);
      const items = Array.isArray(data.items) ? data.items : [];
      const t = Number(data.total);
      const nextTotal = Number.isFinite(t) && t >= 0 ? t : null;
      const res = { items, total: nextTotal };
      chunkCache.set(key, res);
      return res;
    };

    const loadChunk = async (nextChunkStart) => {
      chunkStart = Math.max(0, nextChunkStart);
      const res = await getChunk(chunkStart);
      chunkItems = res.items;
      if (res.total != null) total = res.total;
    };

    const getGlobalPages = () => {
      if (typeof total === "number" && total > 0) {
        return Math.max(1, Math.ceil(total / uiPerPage));
      }
      // fallback: chunk-local only
      return Math.max(1, Math.ceil((chunkItems?.length ?? 0) / uiPerPage));
    };

    const ensureChunkForUiPage = async () => {
      const globalIndex0 = (uiPage - 1) * uiPerPage; // absolute offset
      const desiredChunkStart = Math.floor(globalIndex0 / chunkSize) * chunkSize;
      if (desiredChunkStart !== chunkStart || chunkItems.length === 0) {
        await loadChunk(desiredChunkStart);
      }
    };

    const renderPager = () => {
      pager.innerHTML = "";

      const mk = (label, active, onClick) => {
        const el = document.createElement("span");
        el.className = `ap-page${active ? " is-active" : ""}`;
        el.textContent = label;
        if (onClick) el.addEventListener("click", onClick);
        return el;
      };

      const pages = getGlobalPages();
      const prev = mk("«", false, () => {
        if (uiPage > 1) {
          uiPage -= 1;
          render().catch((e) => console.error(e));
        }
      });

      const next = mk("»", false, () => {
        if (uiPage < pages) {
          uiPage += 1;
          render().catch((e) => console.error(e));
        }
      });

      pager.appendChild(prev);

      const addPage = (p) => {
        pager.appendChild(
          mk(String(p), p === uiPage, () => {
            uiPage = p;
            render().catch((e) => console.error(e));
          })
        );
      };

      // keep UI usable on large totals: 1 ... (cur-2..cur+2) ... last
      const windowSize = 2;
      const startP = Math.max(1, uiPage - windowSize);
      const endP = Math.min(pages, uiPage + windowSize);

      addPage(1);
      if (startP > 2) pager.appendChild(mk("…", false, null));
      for (let p = Math.max(2, startP); p <= Math.min(pages - 1, endP); p += 1) addPage(p);
      if (endP < pages - 1) pager.appendChild(mk("…", false, null));
      if (pages > 1) addPage(pages);

      pager.appendChild(next);

      if (total != null) {
        const info = document.createElement("span");
        info.className = "ap-catalog__total";
        info.textContent = `Всего: ${total}`;
        pager.appendChild(info);
      }
    };

    const render = async () => {
      await ensureChunkForUiPage();
      grid.innerHTML = "";
      const globalIndex0 = (uiPage - 1) * uiPerPage;
      const innerOffset = globalIndex0 - chunkStart; // 0..49

      // If UI page crosses chunk boundary (50), stitch from next chunk.
      const slice = chunkItems.slice(innerOffset, innerOffset + uiPerPage);
      const crossesChunkBoundary = innerOffset + uiPerPage > chunkSize;
      if (crossesChunkBoundary && chunkItems.length > 0) {
        const nextStart = chunkStart + chunkSize;
        const nextChunk = await getChunk(nextStart);
        if (nextChunk.total != null) total = nextChunk.total;
        // Guard against API quirks (some filters may return duplicate pages):
        // - only take what we need
        // - de-dup by product id
        const seen = new Set(slice.map((it) => it?.id).filter(Boolean));
        for (const it of nextChunk.items) {
          if (slice.length >= uiPerPage) break;
          const id = it?.id;
          if (!id || seen.has(id)) continue;
          seen.add(id);
          slice.push(it);
        }
      }

      for (const it of slice) {
        const card = renderCard(it, { showDurationWorkDays });
        card.addEventListener("click", () => {
          const path = (detailPath || "").trim();
          if (path) {
            const base = withQuery(path, { productId: it.id });
            window.location.href = `${base}#${productParam}=${it.id}`;
            return;
          }
          setHashParam(productParam, it.id);
          syncHashProductToQuery(productParam);
        });
        grid.appendChild(card);
      }

      renderPager();
    };

    loadChunk(0)
      .then(() => render())
      .catch((e) => console.error(e));
  };

  const initDetail = (root) => {
    const apiBase = getDataset(root, "apApiBase");
    const productParam = getDataset(root, "apProductParam", "product");

    const title = qs(root, ".ap-detail__title");
    const price = qs(root, ".ap-detail__price");
    const serviceType = qs(root, ".ap-detail__service-type");
    const grid = qs(root, ".ap-detail__grid");
    const req = qs(root, ".ap-detail__requirements");
    const edu = qs(root, ".ap-detail__education");
    const cta = qs(root, ".ap-detail__cta");
    const empty = qs(root, ".ap-detail__empty");
    const content = qs(root, ".ap-detail__content");

    const showEmpty = () => {
      if (empty) empty.hidden = false;
      if (content) content.hidden = true;
      // keep block "empty" without stale data
      if (title) title.textContent = "";
      if (price) price.textContent = "";
      if (serviceType) serviceType.textContent = "";
      if (grid) grid.innerHTML = "";
      if (req) req.innerHTML = "";
      if (edu) edu.innerHTML = "";
    };

    const showContent = () => {
      if (empty) empty.hidden = true;
      if (content) content.hidden = false;
    };

    const render = (dto) => {
      showContent();
      title.textContent = dto?.name ?? "";
      price.textContent = dto?.priceDisplay ?? "";
      const sectionId = Number(dto?.sectionId ?? 0);
      const sectionIdToName = new Map([
        [40, "Рабочие профессии"],
        [68, "Охрана труда"],
        [70, "Повышение квалификации"],
        [72, "Проф.переподготовка"],
        [74, "Лицензирование"],
        [76, "Аттестации специалистов"],
        [78, "Вступление в СРО"],
        [80, "Аудит и расчет рисков"],
      ]);
      serviceType.textContent = sectionIdToName.get(sectionId) ?? (dto?.serviceType ?? "");
      // IMPORTANT: do NOT override CTA label/href here.
      // Button text + link must be controlled by Bitrix editor (node type "link") and block attrs.

      grid.innerHTML = "";
      const trainingSectionIds = new Set([40, 68, 70, 72]);
      const consultingSectionIds = new Set([74, 76, 78, 80]);

      const hasDuration = dto?.durationWorkDays != null && String(dto.durationWorkDays).trim() !== "";
      const hasHours = dto?.hours != null && String(dto.hours).trim() !== "" && String(dto.hours) !== "0";

      const isTraining = trainingSectionIds.has(sectionId);
      const isConsulting = consultingSectionIds.has(sectionId);
      const showDurationInsteadOfHours = isConsulting ? true : !isTraining && hasDuration && !hasHours;

      const fields = [
        { label: "ID", value: dto?.id != null ? String(dto.id) : "" },
        { label: "Периодичность", value: dto?.periodicity ?? "" },
        showDurationInsteadOfHours
          ? { label: "Срок оказания (раб. дней)", value: String(dto?.durationWorkDays ?? "") }
          : { label: "Объем курса (уч. часов)", value: dto?.hours != null ? String(dto.hours) : "" },
        { label: "Реестр", value: dto?.registry ?? "" },
      ];

      const labelsRow = document.createElement("div");
      labelsRow.className = "ap-detail__fields-row ap-detail__fields-row--labels";
      const valuesRow = document.createElement("div");
      valuesRow.className = "ap-detail__fields-row ap-detail__fields-row--values";
      for (const f of fields) {
        const l = document.createElement("div");
        l.className = "ap-detail__field-cell";
        l.textContent = f.label;
        labelsRow.appendChild(l);

        const v = document.createElement("div");
        v.className = "ap-detail__field-cell";
        v.textContent = f.value ?? "";
        valuesRow.appendChild(v);
      }
      grid.appendChild(labelsRow);
      grid.appendChild(valuesRow);

      req.innerHTML = "";
      const reqTitle = document.createElement("div");
      reqTitle.className = "ap-detail__section-title";
      reqTitle.textContent = "Требования к клиенту";
      req.appendChild(reqTitle);
      const list = document.createElement("ul");
      const items = Array.isArray(dto?.requirements) ? dto.requirements : [];
      for (const r of items) {
        const li = document.createElement("li");
        li.textContent = r;
        list.appendChild(li);
      }
      if (items.length > 0) {
        req.appendChild(list);
      } else {
        const empty = document.createElement("div");
        empty.className = "ap-detail__section-empty";
        empty.textContent = "—";
        req.appendChild(empty);
      }

      edu.innerHTML = "";
      const eduTitle = document.createElement("div");
      eduTitle.className = "ap-detail__section-title";
      eduTitle.textContent = "Требуемый уровень образования";
      edu.appendChild(eduTitle);
      const eduV = document.createElement("div");
      eduV.className = "ap-detail__section-value";
      eduV.textContent = dto?.educationLevel ?? "—";
      edu.appendChild(eduV);
    };

    const load = async () => {
      // Ensure embedded CRM-form can read productId from query
      syncHashProductToQuery(productParam);
      const id = getProductIdFromLocation(productParam);
      if (!id) {
        showEmpty();
        return;
      }
      const dto = await fetchJson(`${apiBase}/product?id=${id}`);
      render(dto);
    };

    window.addEventListener("hashchange", () => load().catch((e) => console.error(e)));
    load().catch((e) => console.error(e));
  };

  const init = () => {
    const blocks = qsa(document, "[data-ap-block]");
    for (const root of blocks) {
      const kind = getDataset(root, "apBlock");
      if (kind === "search") initSearch(root);
      if (kind === "catalog") initCatalog(root);
      if (kind === "detail") initDetail(root);
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

