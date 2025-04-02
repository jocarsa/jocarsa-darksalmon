// ---------------------------
// js/codigo.js
// ---------------------------

// Global state variables
let currentEndpoint = null;      // e.g. "Empleados" or "Asistencia"
let currentMeta = null;          // metadata from API { meta: { fields: [...] } }
let fullData = [];               // full data from API (unfiltered)
let currentData = [];            // data currently shown after filtering/sorting
let isEditing = false;           // whether we're editing or inserting
let sortState = {};              // keeps track of sort order per column, e.g., { columnName: "asc" or "desc" }
let filterValues = {};           // stores filter text per column index
let mostrado = true;             // for toggling nav

// DOM element references
const menuContainer = document.querySelector("main nav .enlaces");
const tituloTabla = document.getElementById("titulo-tabla");
const theadEl = document.querySelector("table thead");
const tableBody = document.querySelector("table tbody");
const btnNuevo = document.getElementById("btn-nuevo");
const formulario = document.getElementById("formulario");
const recordIdInput = document.getElementById("record-id");
const camposDiv = document.getElementById("campos");
const formTitle = document.getElementById("form-title");
const btnGuardar = document.getElementById("guardar");
const btnCancelar = document.getElementById("cancelar");

// 1) Load the menu from the API
fetch("api.php?endpoint=menu")
  .then(res => res.json())
  .then(items => {
    items.forEach(item => {
      const div = document.createElement("div");
      const icon = document.createElement("span");
      icon.classList.add("icono", "relieve");
      icon.textContent = item.etiqueta[0]; // first letter as icon
      div.appendChild(icon);

      const txt = document.createElement("span");
      txt.textContent = item.etiqueta;
      div.appendChild(txt);

      // Use the "endpoint" property from the API response rather than the label
      div.onclick = () => {
        document.querySelectorAll("main nav .enlaces div").forEach(el => el.classList.remove("activo"));
        div.classList.add("activo");
        cargarDatos(item.endpoint);
      };
      menuContainer.appendChild(div);
    });
  });

// 2) Fetch data & metadata for a given endpoint and rebuild the entire header
function cargarDatos(endpoint) {
  currentEndpoint = endpoint;
  tituloTabla.textContent = endpoint;
  
  // Clear previous header and body content so no old filter inputs remain
  theadEl.innerHTML = "";
  tableBody.innerHTML = "";
  formulario.style.display = "none";
  document.querySelector("table").style.display = "table";

  // Reset filters and sort state
  filterValues = {};
  sortState = {};

  fetch("api.php?endpoint=" + endpoint)
    .then(r => r.json())
    .then(json => {
      if (json.error) {
        alert("Error: " + json.error);
        return;
      }
      currentMeta = json.meta || {};
      fullData = json.data || [];
      currentData = fullData.slice(); // copy data for filtering/sorting

      // Determine fields to display: use meta.fields if available; otherwise fallback
      let fields = currentMeta.fields || [];
      if (fields.length === 0 && currentData.length > 0) {
        fields = Object.keys(currentData[0]).map(k => ({ name: k, label: k, type: "text" }));
      }
      
      // Create header row with sortable columns
      const headerRow = document.createElement("tr");
      fields.forEach(f => {
        const th = document.createElement("th");
        th.textContent = f.label || f.name;
        th.style.cursor = "pointer";
        th.onclick = () => sortTableByColumn(f.name);
        headerRow.appendChild(th);
      });
      // Add extra header cells for any keys in data not specified in meta
      if (currentData.length > 0) {
        const example = currentData[0];
        Object.keys(example).forEach(k => {
          if (!fields.some(ff => ff.name === k)) {
            const th = document.createElement("th");
            th.textContent = k;
            th.style.cursor = "pointer";
            th.onclick = () => sortTableByColumn(k);
            headerRow.appendChild(th);
          }
        });
      }
      // "Acciones" column (unsortable)
      const thAcc = document.createElement("th");
      thAcc.textContent = "Acciones";
      headerRow.appendChild(thAcc);
      
      // Append the header row to the thead
      theadEl.appendChild(headerRow);
      
      // Create a filter row beneath the header row
      const filterRow = document.createElement("tr");
      // For each header cell, except for the "Acciones" column, add an input
      const headerCells = headerRow.querySelectorAll("th");
      headerCells.forEach((th, index) => {
        const filterCell = document.createElement("td");
        if (th.textContent !== "Acciones") {
          const input = document.createElement("input");
          input.type = "text";
          input.placeholder = "Filtrar";
          input.style.width = "90%";
          input.oninput = (e) => {
            filterValues[index] = e.target.value.toLowerCase();
            applyFilters();
          };
          filterCell.appendChild(input);
        }
        filterRow.appendChild(filterCell);
      });
      // Append the filter row to the thead
      theadEl.appendChild(filterRow);
      
      renderTableRows(fields);
    });
}

// Render table rows using currentData and the provided fields definition
function renderTableRows(fields) {
  tableBody.innerHTML = "";
  currentData.forEach(row => {
    const tr = document.createElement("tr");
    
    // Render cells for each defined field
    fields.forEach(f => {
      const td = document.createElement("td");
      td.innerHTML = row[f.name] !== undefined ? row[f.name] : "";
      tr.appendChild(td);
    });
    // Render extra columns for any keys not in the defined fields
    Object.keys(row).forEach(k => {
      if (!fields.some(ff => ff.name === k)) {
        const td = document.createElement("td");
        td.innerHTML = row[k];
        tr.appendChild(td);
      }
    });
    
    // Build the "Acciones" cell
    const tdAcc = document.createElement("td");
    
    // Edit button
    const btnE = document.createElement("button");
    btnE.classList.add("btn", "actualizar", "relieve");
    btnE.innerHTML = "<span class='emoji'>✏</span>";
    btnE.onclick = () => editarRegistro(row);
    tdAcc.appendChild(btnE);

    // Delete button
    const btnD = document.createElement("button");
    btnD.classList.add("btn", "eliminar", "relieve");
    btnD.innerHTML = "<span class='emoji'>❌</span>";
    btnD.onclick = () => eliminarRegistro(row);
    tdAcc.appendChild(btnD);

    
    tr.appendChild(tdAcc);
    tableBody.appendChild(tr);
  });
}

// Sort the table by a given column (toggle between ascending and descending)
function sortTableByColumn(column) {
  // Toggle sort state for the column
  sortState[column] = sortState[column] === "asc" ? "desc" : "asc";
  const direction = sortState[column];

  currentData.sort((a, b) => {
    let valA = a[column];
    let valB = b[column];

    // If string values, compare case-insensitively
    if (typeof valA === "string") { valA = valA.toLowerCase(); }
    if (typeof valB === "string") { valB = valB.toLowerCase(); }

    if (valA < valB) return direction === "asc" ? -1 : 1;
    if (valA > valB) return direction === "asc" ? 1 : -1;
    return 0;
  });

  // Re-render table rows (using the same fields definition)
  let fields = currentMeta.fields || [];
  if (fields.length === 0 && currentData.length > 0) {
    fields = Object.keys(currentData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }
  renderTableRows(fields);
}

// Apply filters based on the text inputs under each header
function applyFilters() {
  let fields = currentMeta.fields || [];
  if (fields.length === 0 && fullData.length > 0) {
    fields = Object.keys(fullData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }
  // Filter fullData using the filterValues (by column index)
  currentData = fullData.filter(row => {
    let match = true;
    let colIndex = 0;
    fields.forEach(f => {
      const filterText = filterValues[colIndex] || "";
      if (filterText && row[f.name] !== undefined) {
        if (String(row[f.name]).toLowerCase().indexOf(filterText) === -1) {
          match = false;
        }
      }
      colIndex++;
    });
    return match;
  });
  renderTableRows(fields);
}

// 3) "Nuevo registro" => Show empty form for a new record
btnNuevo.onclick = function() {
  if (!currentEndpoint) return;
  isEditing = false;
  recordIdInput.value = "";
  formTitle.textContent = "Nuevo registro en " + currentEndpoint;
  buildFormFields({});
  // Hide table view, show form only
  document.querySelector("table").style.display = "none";
  formulario.style.display = "block";
};

// 4) Build the insert/edit form fields based on meta
function buildFormFields(existingRow) {
  camposDiv.innerHTML = "";
  let fields = currentMeta.fields || [];
  if (fields.length === 0 && fullData.length > 0) {
    fields = Object.keys(fullData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }

  fields.forEach(f => {
    // Skip "id" or read-only fields if desired
    if (f.name === "id") return;
    const labelEl = document.createElement("label");
    labelEl.textContent = f.label || f.name;
    
    let inputEl;
    if (f.type === "select" && Array.isArray(f.options)) {
      inputEl = document.createElement("select");
      f.options.forEach(opt => {
        const o = document.createElement("option");
        o.value = opt.value;
        o.textContent = opt.label;
        inputEl.appendChild(o);
      });
      if (existingRow[f.name] !== undefined && existingRow[f.name] !== null) {
        inputEl.value = existingRow[f.name];
      }
    } else {
      inputEl = document.createElement("input");
      inputEl.type = (f.type === "number") ? "number" : (f.type === "date" ? "date" : (f.type === "time" ? "time" : "text"));
      if (existingRow[f.name] !== undefined) {
        inputEl.value = existingRow[f.name];
      }
      if (f.readonly) {
        inputEl.disabled = true;
      }
    }
    inputEl.id = "input-" + f.name;
    camposDiv.appendChild(labelEl);
    camposDiv.appendChild(inputEl);
  });
}

// 5) Edit: fill the form with the record's data
function editarRegistro(row) {
  isEditing = true;
  recordIdInput.value = row.id || "";
  formTitle.textContent = "Editar registro en " + currentEndpoint;
  buildFormFields(row);
  document.querySelector("table").style.display = "none";
  formulario.style.display = "block";
}

// 6) Delete: confirm deletion and call API to remove the record
function eliminarRegistro(row) {
  if (!confirm("¿Eliminar este registro?")) return;
  const id = row.id;
  fetch(`api.php?endpoint=${currentEndpoint}&id=${id}`, {
    method: "DELETE"
  })
  .then(r => r.json())
  .then(res => {
    if (res.error) {
      alert("Error: " + res.error);
    } else {
      cargarDatos(currentEndpoint);
    }
  });
}

// 7) Cancel: hide the form and show the table again
btnCancelar.onclick = () => {
  formulario.style.display = "none";
  document.querySelector("table").style.display = "table";
};

// 8) Save: submit the form data to the API (POST for new, PUT for update)
btnGuardar.onclick = () => {
  if (!currentEndpoint) return;
  let fields = currentMeta.fields || [];
  if (fields.length === 0 && fullData.length > 0) {
    fields = Object.keys(fullData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }
  const dataObj = {};
  if (isEditing) dataObj.id = recordIdInput.value;

  fields.forEach(f => {
    if (f.name === "id") return;
    const el = document.getElementById("input-" + f.name);
    if (el) dataObj[f.name] = el.value;
  });

  if (!isEditing) {
    // Create new record
    fetch(`api.php?endpoint=${currentEndpoint}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(dataObj)
    })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        alert("Error: " + res.error);
      } else {
        formulario.style.display = "none";
        document.querySelector("table").style.display = "table";
        cargarDatos(currentEndpoint);
      }
    });
  } else {
    // Update existing record
    const id = recordIdInput.value;
    fetch(`api.php?endpoint=${currentEndpoint}&id=${id}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(dataObj)
    })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        alert("Error: " + res.error);
      } else {
        formulario.style.display = "none";
        document.querySelector("table").style.display = "table";
        cargarDatos(currentEndpoint);
      }
    });
  }
};

// 9) Toggle the left navigation bar visibility (existing functionality)
document.getElementById("ocultar").onclick = function() {
  const nav = document.querySelector("main nav");
  const icon = this.querySelector(".icono");
  if (mostrado) {
    nav.style.width = "55px";
    icon.style.transform = "rotate(0deg)";
    mostrado = false;
  } else {
    nav.style.width = "200px";
    icon.style.transform = "rotate(180deg)";
    mostrado = true;
  }
};

// 10) New Vertical Toggle Action
let verticalExpanded = false;
document.getElementById("toggleVertical").onclick = function() {
  const sectionEl = document.querySelector("section");
  if (!verticalExpanded) {
    sectionEl.classList.add("vertical-full");
    this.innerHTML = "<span class='icono relieve'>⇕</span>Reducir Vertical";
  } else {
    sectionEl.classList.remove("vertical-full");
    this.innerHTML = "<span class='icono relieve'>⇕</span>Expandir Vertical";
  }
  verticalExpanded = !verticalExpanded;
};

