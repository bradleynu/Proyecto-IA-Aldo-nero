let CATALOG = [];
const catalogDiv = document.getElementById("catalogo");
const rawOutput = document.getElementById("rawOutput");
const backdrop = document.getElementById("backdrop");

// Para mostrar colores como etiquetas
function tag(color) {
  return `<span class="tag" style="background:${color}; border:1px solid #333;">${color}</span>`;
}

// Cargar catálogo desde products.json
async function loadCatalog() {
  try {
    const res = await fetch("products.json");
    if (!res.ok) throw new Error(`No se pudo cargar products.json (HTTP ${res.status})`);
    CATALOG = await res.json();
    renderCatalog(CATALOG);
  } catch (err) {
    catalogDiv.innerHTML = `<p style="color:red;">Error cargando el catálogo: ${err.message}</p>`;
    console.error(err);
  }
}

// Renderizar catálogo en pantalla
function renderCatalog(items) {
  catalogDiv.innerHTML = "";
  items.forEach(p => {
    const el = document.createElement("article");
    el.className = "card";
    el.innerHTML = `
      <div class="img"><img src="${p.image}" alt="${p.name}" loading="lazy" /></div>
      <div class="content">
        <h3>${p.name}</h3>
        <div class="meta">${p.category}</div>
        <div class="tags">${(p.colors || []).map(c => tag(c)).join("")}</div>
        <div class="actions">
          <button class="btn primary" data-id="${p.id}">Crear outfit con este</button>
        </div>
      </div>
    `;
    catalogDiv.appendChild(el);
  });

  // Vincular botones
  catalogDiv.querySelectorAll("button[data-id]").forEach(btn => {
    btn.addEventListener("click", async (e) => {
      const id = e.currentTarget.getAttribute("data-id");
      await requestOutfit(id);
    });
  });
}

// Petición al backend (api.php)
async function requestOutfit(baseId) {
    try {
        console.log("➡️ requestOutfit start", { baseId });
        backdrop.hidden = false;

        const res = await fetch("./api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ baseProductId: baseId })
        });

        if (!res.ok) {
            throw new Error(`Error en la petición a api.php (HTTP ${res.status})`);
        }

        const data = await res.json();
        console.log("📦 response JSON", data);

        if (data.error) {
            throw new Error(data.error);
        }

        rawOutput.hidden = true; // Ocultamos el 'raw output' que ya no es necesario

        renderSuggestions(data.baseProduct, data.suggestions || []);

    } catch (err) {
        console.error("❌ requestOutfit error", err);
        alert("Error generando outfit: " + err.message);
    } finally {
        console.log("✔️ finally executed → ocultar loader");
        backdrop.hidden = true;
    }
}

// Renderizar el outfit en el nuevo layout "Lookbook"
function renderSuggestions(baseProduct, suggestions = []) {
  const outfitContainer = document.getElementById("outfit-display");
  outfitContainer.innerHTML = ""; // Limpiamos antes de dibujar

  if (!baseProduct) {
    outfitContainer.hidden = true;
    return;
  }
  outfitContainer.hidden = false;

  // Función interna para crear el HTML de una tarjeta
  const createCardHTML = (p) => `
    <div class="img"><img src="${p.image}" alt="${p.name}" loading="lazy" /></div>
    <div class="content">
      <h3>${p.name}</h3>
      <div class="meta">${p.category}</div>
      <div class="tags">${(p.colors || []).map(c => tag(c)).join("")}</div>
    </div>
  `;

  // 1. Crea y añade la prenda BASE al centro
  const baseEl = document.createElement("article");
  baseEl.className = "card outfit-base-item";
  baseEl.innerHTML = createCardHTML(baseProduct);
  outfitContainer.appendChild(baseEl);

  // 2. Crea y añade las SUGERENCIAS alrededor
  suggestions.forEach((p, index) => {
    if (index < 4) { // Limitamos a un máximo de 4 sugerencias
      const suggestionEl = document.createElement("article");
      suggestionEl.className = `card outfit-suggestion-${index + 1}`;
      suggestionEl.innerHTML = createCardHTML(p);
      outfitContainer.appendChild(suggestionEl);
    }
  });
}

// Botón limpiar sugerencias
const clearBtn = document.getElementById("clearSuggestions");
if (clearBtn) {
  clearBtn.addEventListener("click", () => {
    document.getElementById("outfit-display").hidden = true;
    rawOutput.hidden = true;
  });
}

// Iniciar
loadCatalog();