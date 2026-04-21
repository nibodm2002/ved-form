/**
 * Конфигурация категорий дел.
 * На бэкенде заменяется на чтение из categories_config (txt/yaml).
 * Поле hasAddress: true — означает, что на шаге 2 визарда показывается поле «Адрес объекта».
 */
const CATEGORIES = [
  {
    id: 1,
    name: "Проверка квартиры перед покупкой",
    icon: "house-check",
    hasAddress: true,
  },
  {
    id: 2,
    name: "Сопровождение сделки с недвижимостью",
    icon: "building",
    hasAddress: true,
  },
  {
    id: 3,
    name: "Арбитражное судопроизводство",
    icon: "scale-balanced",
    hasAddress: false,
  },
  {
    id: 4,
    name: "Налоговые проверки и консалтинг",
    icon: "chart-pie",
    hasAddress: false,
  },
  {
    id: 5,
    name: "Наследственное планирование",
    icon: "scroll",
    hasAddress: false,
  },
  {
    id: 6,
    name: "Корпоративное управление и конфликты",
    icon: "landmark",
    hasAddress: false,
  },
  {
    id: 7,
    name: "Другое",
    icon: "comments",
    hasAddress: false,
  },
];
