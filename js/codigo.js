// -- Global references
let currentEndpoint = null;    // e.g. "Empleados"
let currentMeta = null;        // from {meta:{fields:[...]}}
let currentData = null;        // from {data:[...]}
let isEditing = false;         // whether we're editing or inserting

// DOM elements
const menuContainer = document.querySelector("main nav .enlaces");
const tituloTabla = document.getElementById("titulo-tabla");
const tableHead = document.querySelector("table thead tr");
const tableBody = document.querySelector("table tbody");
const btnNuevo = document.getElementById("btn-nuevo");
const formulario = document.getElementById("formulario");
const recordIdInput = document.getElementById("record-id");
const camposDiv = document.getElementById("campos");
const formTitle = document.getElementById("form-title");
const btnGuardar = document.getElementById("guardar");
const btnCancelar = document.getElementById("cancelar");
let mostrado = true; // For toggling nav

// 1) Load the menu from the API
fetch("api.php?endpoint=menu")
  .then(res => res.json())
  .then(items => {
    items.forEach(item => {
      const div = document.createElement("div");
      const icon = document.createElement("span");
      icon.classList.add("icono", "relieve");
      icon.textContent = item.etiqueta[0]; // first letter
      div.appendChild(icon);

      const txt = document.createElement("span");
      txt.textContent = item.etiqueta;
      div.appendChild(txt);

      div.onclick = () => {
        document.querySelectorAll("main nav .enlaces div").forEach(el => el.classList.remove("activo"));
        div.classList.add("activo");
        cargarDatos(item.etiqueta);
      };
      menuContainer.appendChild(div);
    });
  });

// 2) Function to fetch data & meta for a given endpoint
function cargarDatos(endpoint) {
  currentEndpoint = endpoint;
  tituloTabla.textContent = endpoint;
  tableHead.innerHTML = "";
  tableBody.innerHTML = "";
  formulario.style.display = "none";
  
  // Ensure table view is visible
  document.querySelector("table").style.display = "table";

  fetch("api.php?endpoint=" + endpoint)
    .then(r => r.json())
    .then(json => {
      if (json.error) {
        alert("Error: " + json.error);
        return;
      }
      // Expecting { meta: {fields:[...]}, data: [...] }
      currentMeta = json.meta || {};
      currentData = json.data || [];

      // Build table header
      let fields = currentMeta.fields || [];
      // if meta empty but we have data => fallback to keys of first row
      if (fields.length === 0 && currentData.length > 0) {
        fields = Object.keys(currentData[0]).map(k => ({ name: k, label: k, type: "text" }));
      }

      // Build <th> for each field
      fields.forEach(f => {
        const th = document.createElement("th");
        th.textContent = f.label || f.name;
        tableHead.appendChild(th);
      });

      // Also handle "extra" columns from joined data 
      if (currentData.length > 0) {
        const example = currentData[0];
        Object.keys(example).forEach(k => {
          if (!fields.some(ff => ff.name === k)) {
            const th = document.createElement("th");
            th.textContent = k;
            tableHead.appendChild(th);
          }
        });
      }

      // Action column
      const thAcc = document.createElement("th");
      thAcc.textContent = "Acciones";
      tableHead.appendChild(thAcc);

      // Build rows
      currentData.forEach(row => {
        const tr = document.createElement("tr");
        // fields
        fields.forEach(f => {
          const td = document.createElement("td");
          td.textContent = (row[f.name] !== undefined) ? row[f.name] : "";
          tr.appendChild(td);
        });

        // extra columns
        if (currentData.length > 0) {
          const exampleKeys = Object.keys(row);
          exampleKeys.forEach(k => {
            if (!fields.some(ff => ff.name === k)) {
              const td = document.createElement("td");
              td.textContent = row[k];
              tr.appendChild(td);
            }
          });
        }

        // actions
        const tdAcc = document.createElement("td");
        const btnE = document.createElement("button");
        btnE.classList.add("btn", "actualizar","relieve");
        btnE.innerHTML = "<span class='emoji'>✏</span>";
        btnE.onclick = () => editarRegistro(row);
        tdAcc.appendChild(btnE);

        const btnD = document.createElement("button");
        btnD.classList.add("btn", "eliminar","relieve");
        btnD.innerHTML = "<span class='emoji'>❌</span>";
        btnD.onclick = () => eliminarRegistro(row);
        tdAcc.appendChild(btnD);

        tr.appendChild(tdAcc);
        tableBody.appendChild(tr);
      });
    });
}

// 3) "Nuevo registro" => show empty form
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

// 4) Build form fields from meta
function buildFormFields(existingRow) {
  camposDiv.innerHTML = "";
  let fields = currentMeta.fields || [];
  // fallback
  if (fields.length === 0 && currentData.length > 0) {
    fields = Object.keys(currentData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }

  fields.forEach(f => {
    // skip "id" or read-only fields if we want them hidden
    if (f.name === "id") return;
    // create label + input
    const labelEl = document.createElement("label");
    labelEl.textContent = f.label || f.name;
    
    let inputEl;
    if (f.type === "select" && Array.isArray(f.options)) {
      // build a <select>
      inputEl = document.createElement("select");
      f.options.forEach(opt => {
        const o = document.createElement("option");
        o.value = opt.value;
        o.textContent = opt.label;
        inputEl.appendChild(o);
      });
      // set existing value
      if (existingRow[f.name] !== undefined && existingRow[f.name] !== null) {
        inputEl.value = existingRow[f.name];
      }
    } else {
      // text, number, date, time or default input
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

// 5) Edit => fill form
function editarRegistro(row) {
  isEditing = true;
  recordIdInput.value = row.id || "";
  formTitle.textContent = "Editar registro en " + currentEndpoint;
  buildFormFields(row);
  // Hide table view, show form only
  document.querySelector("table").style.display = "none";
  formulario.style.display = "block";
}

// 6) Delete => confirm + fetch
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
      // reload
      cargarDatos(currentEndpoint);
    }
  });
}

// 7) Cancel form
btnCancelar.onclick = () => {
  formulario.style.display = "none";
  // Show table view again
  document.querySelector("table").style.display = "table";
};

// 8) Save => POST or PUT
btnGuardar.onclick = () => {
  if (!currentEndpoint) return;
  let fields = currentMeta.fields || [];
  if (fields.length === 0 && currentData.length > 0) {
    fields = Object.keys(currentData[0]).map(k => ({ name: k, label: k, type: "text" }));
  }
  // build data object
  const dataObj = {};
  if (isEditing) dataObj.id = recordIdInput.value;

  fields.forEach(f => {
    if (f.name === "id") return;
    const el = document.getElementById("input-" + f.name);
    if (el) dataObj[f.name] = el.value;
  });

  if (!isEditing) {
    // POST
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
        // Show table view again
        document.querySelector("table").style.display = "table";
        cargarDatos(currentEndpoint);
      }
    });
  } else {
    // PUT
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
        // Show table view again
        document.querySelector("table").style.display = "table";
        cargarDatos(currentEndpoint);
      }
    });
  }
};

// 9) Toggle nav (ocultar)
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

