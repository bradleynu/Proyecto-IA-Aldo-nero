let CATALOG = [];
const catalogDiv = document.getElementById("catalogo");
const suggestionsDiv = document.getElementById("suggestions");
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
      const occ = document.getElementById("occasion").value;
      const sty = document.getElementById("style").value;
      await requestOutfit(id, { occasion: occ, style: sty });
    });
  });
}
// Petición al backend (api.php)
async function requestOutfit(baseId, prefs = {}) {
    try {
        console.log("➡️ requestOutfit start", { baseId, prefs });
        backdrop.hidden = false;

        const res = await fetch("./api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ baseProductId: baseId, preferences: prefs })
        });

        console.log("📡 status", res.status);

        const data = await res.json();
        console.log("📦 response JSON", data);

        if (data.error) {
            throw new Error(data.error);
        }

        rawOutput.hidden = false;
        rawOutput.textContent = JSON.stringify(data.raw || {}, null, 2);

        renderSuggestions(data.suggestions || []);
    } catch (err) {
        console.error("❌ requestOutfit error", err);
        alert("Error generando outfit: " + err.message);
    } finally {
        console.log("✔️ finally executed → ocultar loader");
        backdrop.hidden = true;
    }
}


// Renderizar sugerencias del backend
function renderSuggestions(arr) {
  suggestionsDiv.innerHTML = "";
  if (!arr.length) {
    suggestionsDiv.innerHTML = "<p>No hubo sugerencias.</p>";
    return;
  }

  arr.forEach(p => {
    const el = document.createElement("article");
    el.className = "card";
    el.innerHTML = `
      <div class="img"><img src="${p.image}" alt="${p.name}" loading="lazy" /></div>
      <div class="content">
        <h3>${p.name}</h3>
        <div class="meta">${p.category}</div>
        <div class="tags">${(p.colors || []).map(c => tag(c)).join("")}</div>
      </div>
    `;
    suggestionsDiv.appendChild(el);
  });
}

// Botón limpiar sugerencias
const clearBtn = document.getElementById("clearSuggestions");
if (clearBtn) {
  clearBtn.addEventListener("click", () => {
    suggestionsDiv.innerHTML = "";
    rawOutput.hidden = true;
    rawOutput.textContent = "";
  });
}

// Iniciar
loadCatalog();
