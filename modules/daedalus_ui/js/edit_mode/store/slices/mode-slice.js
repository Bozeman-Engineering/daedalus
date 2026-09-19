import { createSlice } from '@reduxjs/toolkit';
import store from '../store';

const modeSlice = createSlice({
  name: 'mode',
  initialState: {
    mode: null,
  },
  reducers: {
    setMode: (state, action) => {
      state.mode = action.payload;
    },
  },
});

export const { setMode } = modeSlice.actions;
export default modeSlice.reducer;

window.setMode = (mode) => {
  store.dispatch(setMode({ mode }));
};

window.currentMode = () => {
  return store.getState().mode.mode;
};
