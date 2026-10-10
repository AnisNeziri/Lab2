import { businessMoney, businessDate } from '../utils/businessFormat.js'
export const formatOrderMoney = (value, currency, language = 'en') => businessMoney(value, currency || '', language)
export const formatOrderDate = businessDate
